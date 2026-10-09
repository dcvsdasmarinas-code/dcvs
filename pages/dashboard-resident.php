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
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/waitlist.php';
require_once __DIR__ . '/../includes/adoption-workflow.php';

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

adoptionReleaseExpiredReservations($conn);

if (!empty($_SESSION['adoption_apply_listing_id'])) {
    $pendingAdoptionListingId = (int)$_SESSION['adoption_apply_listing_id'];
    unset($_SESSION['adoption_apply_listing_id']);
    header('Location: resident-adoption-applications.php?apply=' . $pendingAdoptionListingId);
    exit;
}

// Adopt Me now routes through the reviewed application workflow.
$showAdoptModal = false;
$adoptData = null;
$adoptListingId = null;
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
$stmt = $conn->prepare("SELECT pet_id, reference_id, pet_name, pet_type, breed, age, weight, gender, color, vaccination_status, photo_url, created_at, EXISTS(SELECT 1 FROM adoption_listings WHERE pet_id = p.pet_id) AS from_adoption, (SELECT DATE(COALESCE(source_listing.adopted_at, NULLIF(al.updated_at,''), al.created_at)) FROM adoption_listings al LEFT JOIN pet_listings source_listing ON source_listing.listing_id = al.source_listing_id WHERE al.pet_id = p.pet_id AND al.status = 'adopted' ORDER BY al.updated_at DESC LIMIT 1) AS adopted_date FROM pets p WHERE owner_id = ? AND archived_at IS NULL ORDER BY pet_id ASC");
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
$stmt = $conn->prepare("SELECT r.report_id, r.reference_id, r.report_type, r.animal_type, r.animal_breed, r.animal_count, r.animal_behavior, r.description, r.incident_at, r.location_address, r.status, r.created_at, r.updated_at, r.photo_url, r.video_url, r.urgency_level, rw.response_mode, rw.state AS response_state, rw.response_deadline, rw.progress_deadline, rw.turnover_deadline, rw.holding_location, rr.resolution_details, rr.animal_disposition, rr.resolved_at AS response_resolved_at, tt.status AS turnover_status, tt.due_at AS turnover_due_at, tt.received_at AS turnover_received_at FROM reports r LEFT JOIN report_response_workflow rw ON rw.report_id = r.report_id LEFT JOIN report_response_resolutions rr ON rr.report_id = r.report_id LEFT JOIN report_turnover_tasks tt ON tt.report_id = r.report_id WHERE r.reporter_id = ? AND r.archived_at IS NULL ORDER BY r.report_id ASC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}
$stmt->close();

// Fetch archived reports
$archivedReports = [];
$stmt = $conn->prepare("SELECT report_id, reference_id, report_type, description, location_address, status, created_at, archived_at, photo_url, video_url FROM reports WHERE reporter_id = ? AND archived_at IS NOT NULL ORDER BY report_id ASC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $archivedReports[] = $row;
}
$stmt->close();

// Fetch user's appointments from database (excluding archived)
$appointments = [];
$stmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.urgency_level, a.preferred_date, a.preferred_time, a.status, a.pet_id, a.notes, a.created_at, COALESCE(p.pet_name, '-') AS pet_name, COALESCE(p.photo_url, '') AS pet_photo FROM appointments a LEFT JOIN pets p ON a.pet_id = p.pet_id WHERE a.user_id = ? AND a.archived_at IS NULL ORDER BY a.appointment_id ASC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $appointments[] = $row;
}
$stmt->close();

dcvsExpireWaitlistClaims($conn, (int)$_SESSION['user_id']);
$residentWaitlist = [];
$waitlistStmt = $conn->prepare("SELECT w.waitlist_id, w.pet_id, w.appointment_type, w.urgency_level, w.preferred_date, w.preferred_time, w.status, w.created_at, w.notified_at, w.claim_expires_at, p.pet_name, p.photo_url AS pet_photo
    FROM appointment_waitlist w
    LEFT JOIN pets p ON p.pet_id = w.pet_id AND p.owner_id = w.user_id
    WHERE w.user_id = ? ORDER BY w.created_at DESC");
if (!$waitlistStmt) {
    error_log('Failed to prepare resident waitlist query: ' . $conn->error);
} else {
    $waitlistStmt->bind_param('i', $_SESSION['user_id']);
    if (!$waitlistStmt->execute()) {
        error_log('Failed to query resident waitlist: ' . $waitlistStmt->error);
    } else {
        $residentWaitlist = $waitlistStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $waitlistStmt->close();
}
$residentWaitlistStats = ['total' => count($residentWaitlist), 'waiting' => 0, 'notified' => 0];
$residentWaitlistDemandDate = null;
$residentWaitlistDemandCount = 0;
$residentWaitlistDemand = [];
foreach ($residentWaitlist as $waitlistEntry) {
    if (isset($residentWaitlistStats[$waitlistEntry['status']])) {
        $residentWaitlistStats[$waitlistEntry['status']]++;
    }
    if ($waitlistEntry['status'] === 'waiting') {
        $demandDateKey = $waitlistEntry['preferred_date'];
        $residentWaitlistDemand[$demandDateKey] = ($residentWaitlistDemand[$demandDateKey] ?? 0) + 1;
    }
}
if ($residentWaitlistDemand) {
    arsort($residentWaitlistDemand);
    $residentWaitlistDemandDate = (string)array_key_first($residentWaitlistDemand);
    $residentWaitlistDemandCount = (int)$residentWaitlistDemand[$residentWaitlistDemandDate];
}
$residentWaitlistBooking = null;
$requestedWaitlistBookingId = (int)($_GET['waitlist_booking'] ?? 0);
if ($requestedWaitlistBookingId > 0) {
    foreach ($residentWaitlist as $entry) {
        if ((int)$entry['waitlist_id'] === $requestedWaitlistBookingId && $entry['status'] === 'notified') {
            $residentWaitlistBooking = [
                'pet_id' => (int)$entry['pet_id'],
                'appointment_type' => $entry['appointment_type'],
                'urgency_level' => $entry['urgency_level'],
                'preferred_date' => $entry['preferred_date'],
                'preferred_time' => $entry['preferred_time'] ? substr($entry['preferred_time'], 0, 5) : null,
            ];
            break;
        }
    }
}

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
           pl.status AS pl_status,
           pl.adopted_at AS pl_adopted_at,
           pl.updated_at AS pl_updated_at,
           (SELECT status FROM appointments WHERE pet_id = al.pet_id AND archived_at IS NULL ORDER BY created_at DESC LIMIT 1) AS appt_status,
           (SELECT preferred_date FROM appointments WHERE pet_id = al.pet_id AND archived_at IS NULL ORDER BY created_at DESC LIMIT 1) AS appt_date,
           (SELECT reference_id FROM appointments WHERE pet_id = al.pet_id AND archived_at IS NULL ORDER BY created_at DESC LIMIT 1) AS appt_ref,
           (SELECT appointment_id FROM appointments WHERE pet_id = al.pet_id AND archived_at IS NULL ORDER BY created_at DESC LIMIT 1) AS appt_id
    FROM adoption_listings al
    JOIN pets p ON al.pet_id = p.pet_id
    LEFT JOIN pet_listings pl
        ON pl.listing_id = al.source_listing_id
        OR (al.source_listing_id IS NULL AND LOWER(pl.name) = LOWER(p.pet_name))
    WHERE al.listed_by = ? AND al.status IN ('available','pending','adopted')
    ORDER BY FIELD(al.status, 'pending', 'available', 'adopted'), al.created_at DESC
");
$alq->bind_param("i", $userId);
$alq->execute();
$alResult = $alq->get_result();
while ($row = $alResult->fetch_assoc()) $adoptionListings[] = $row;
$alq->close();

$residentAdoptionApplications = [];
$applicationStmt = $conn->prepare("
    SELECT aa.application_id, aa.application_date, aa.status, aa.pet_listing_id, aa.resulting_pet_id,
           pl.name AS pet_name, pl.species, pl.breed, pl.age, pl.gender, pl.photo_url,
           aa.approved_pickup_at, aa.finalized_at
    FROM adoption_applications aa
    JOIN pet_listings pl ON pl.listing_id = aa.pet_listing_id
    WHERE aa.applicant_id = ?
      AND aa.status IN ('pending','under_review','needs_info','approved','adopted','expired')
    ORDER BY aa.application_date DESC, aa.application_id DESC
");
if (!$applicationStmt) {
    throw new RuntimeException('Your adoption applications could not be loaded.');
}
$applicationStmt->bind_param('i', $userId);
$applicationStmt->execute();
$applicationResult = $applicationStmt->get_result();
while ($row = $applicationResult->fetch_assoc()) {
    $residentAdoptionApplications[] = $row;
}
$applicationStmt->close();

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

// Upcoming appointments count (matches todayAppts query)
$upcomingApptCount = count($todayAppts);

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
<html lang="en" class="resident-portal">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="description" content="Resident Dashboard - Dasmari&ntilde;as City Veterinary Services Animal Welfare System" />
  <title><?php echo htmlspecialchars($pageTitle); ?> - Resident | DCVS</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/css/dashboard.css" />
  <link rel="stylesheet" href="assets/css/responsive.css?v=<?php echo (int)filemtime(__DIR__ . '/../assets/css/responsive.css'); ?>&resident-dialog=2" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    :root {
      --primary-green: #0B4D26;
      --primary-green-hover: #083B1D;
      --primary-green-light: #EBF7EE;
      --bg-page: #FFFFFF;
      --bg-card: #FFFFFF;
      --bg-subtle: #F9FAFB;
      --bg-hover: #F3F4F6;
      --border-color: #E5E7EB;
      --border-color-light: #F0F2F5;
      --border-color-dark: #D1D5DB;
      --text-main: #111827;
      --text-secondary: #4B5563;
      --text-muted: #6B7280;
      --text-faint: #9CA3AF;
      --radius-sm: 6px;
      --radius-md: 10px;
      --radius-lg: 14px;
      --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      font-size: 13.5px;
      line-height: 1.5;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }
    .page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; flex-wrap:wrap; gap:0.75rem; }
    .page-title { font-size:1.5rem; font-weight:600; color:#18181b; margin:0; font-family:'Inter',sans-serif; letter-spacing:-0.025em; }
    .page-subtitle { font-size:0.9rem; color:#71717a; margin:4px 0 0; font-family:'Inter',sans-serif; }
    .header-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .dashboard-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.04); }
    .card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
    .card-header h3 { font-size:1rem; font-weight:700; color:#0f172a; margin:0; }
    .kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1rem; margin-bottom:1.75rem; }
    .kpi-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem; box-shadow:0 1px 3px rgba(0,0,0,0.04); }
    .kpi-label { font-size:0.78rem; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:0.03em; }
    .kpi-value { font-size:1.75rem; font-weight:800; color:#0f172a; font-family:'Inter',sans-serif; margin-top:4px; }
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
    .archive-title { font-size:1.1rem; font-weight:600; color:#18181b; margin:0; font-family:'Inter',sans-serif; letter-spacing:-0.01em; }
    .archive-section { margin-top:0; padding-top:0; border-top:none; }
    #archive .archive-section { display:none !important; }
    #archive .archive-unified-table-area { min-height:var(--staff-table-min-height); overflow:hidden; }
    #archive .archive-unified-table-area table { width:100%; border-collapse:collapse; }
    #archive .archive-unified-table-area th { height:40px; padding:0 12px; text-align:left; vertical-align:middle; white-space:nowrap; }
    #archive .archive-unified-table-area td { padding:10px 12px; vertical-align:middle; }
    #archive .archive-unified-table-area .archive-empty-row td { height:var(--staff-table-min-height); text-align:center; color:#71717a; }
    #archive .archive-unified-table-area .archive-empty-row i { display:block; margin-bottom:10px; color:#cbd5e1; font-size:1.5rem; }
    #archive #archivePagination { min-height:57px; margin-top:0; border-top:1px solid #e4e4e7; background:#fff; }
    .archive-section h2 { font-size:1.1rem; font-weight:600; color:#18181b; margin:0 0 1rem 0; font-family:'Inter',sans-serif; letter-spacing:-0.01em; }
    #archive .archive-header { justify-content:flex-start; }
    #archive .archive-toolbar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; width:100%; }
    #archive .archive-toolbar .search-grow-wrap { position:relative; width:280px; min-width:180px; }
    #archive .archive-search-icon { position:absolute; left:12px; top:50%; transform:translateY(-50%); width:15px; height:15px; color:#71717a; pointer-events:none; }
    #archive .archive-search-input { height:36px; width:100%; padding:0 12px 0 36px; border:1px solid #e4e4e7; border-radius:6px; background:#fff; color:#18181b; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; outline:none; box-shadow:0 1px 2px rgba(0,0,0,.05); }
    #archive .archive-search-input:focus { border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #archive .archive-search-input::placeholder { color:#a1a1aa; }
    #archive .archive-filter-select { height:36px; min-width:140px; padding:0 28px 0 10px; border:1px solid #e4e4e7; border-radius:6px; background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 10px center; color:#18181b; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; appearance:none; -webkit-appearance:none; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.05); }
    #archive .archive-filter-select:focus { outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    @media (max-width:768px) {
      #archive .archive-toolbar { align-items:stretch; flex-direction:column; }
      #archive .archive-toolbar .search-grow-wrap,
      #archive .archive-filter-select { width:100%; min-width:0; }
    }
    #archive .appt-table { font-family:'Inter',sans-serif; }
    .archive-empty { text-align:center; padding:2.5rem 1rem; color:#71717a; font-family:'Inter',sans-serif; }
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
    .search-box input { border:none; background:transparent; padding:8px 10px; font-size:0.85rem; color:#18181b; outline:none; width:100%; min-width:180px; font-family:'Inter',sans-serif; }
    .search-box input::placeholder { color:#71717a; }
    .filter-select { padding:8px 14px; border-radius:6px; border:1px solid #e4e4e7; font-size:0.85rem; background:#fff; color:#18181b; cursor:pointer; transition:border-color 0.15s; font-family:'Inter',sans-serif; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; padding-right:36px; }
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
/* ï¿½"?ï¿½"? Profile Settings (new layout) ï¿½"?ï¿½"? */
.profile-settings-wrapper * { box-sizing:border-box; }
.profile-settings-wrapper { max-width:680px; margin:0 auto; }
.profile-settings-wrapper .ps-card { background:#fff; border:1px solid #e6e8eb; border-radius:14px; padding:22px 24px; margin-bottom:20px; box-shadow:0 1px 2px rgba(0,0,0,0.02); }
.profile-settings-wrapper .ps-profile-card { display:flex; align-items:center; justify-content:space-between; }
.profile-settings-wrapper .ps-profile-left { display:flex; align-items:center; gap:16px; }
.profile-settings-wrapper .ps-avatar { width:56px; height:56px; border-radius:50%; overflow:hidden; flex-shrink:0; background:#d9d9d9; cursor:pointer; }
.profile-settings-wrapper .ps-avatar img { width:100%; height:100%; object-fit:cover; }
.profile-settings-wrapper .ps-avatar-plain { width:56px; height:56px; border-radius:50%; background:#d9d9d9; display:flex; align-items:center; justify-content:center; font-size:1.3rem; color:#666; cursor:pointer; }
.profile-settings-wrapper .ps-profile-name { font-family:'Inter',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
.profile-settings-wrapper .ps-profile-sub { font-family:'Inter',sans-serif; font-size:14px; color:#71717a; line-height:1.5; margin-top:2px; }
.profile-settings-wrapper .ps-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
.profile-settings-wrapper .ps-card-header h2 { font-family:'Inter',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; margin:0; }
.profile-settings-wrapper .ps-edit-btn { display:inline-flex; align-items:center; gap:6px; background:#fff; border:1px solid #d7dae0; border-radius:8px; padding:7px 14px; font-family:'Inter',sans-serif; font-size:14px; font-weight:500; color:#18181b; cursor:pointer; transition:background .15s,border-color .15s; }
.profile-settings-wrapper .ps-edit-btn:hover { background:#f5f6f8; border-color:#c3c8d0; }
.profile-settings-wrapper .ps-edit-btn.active { background:#f0f0f0; border-color:#000; color:#000; }
.profile-settings-wrapper .ps-edit-btn svg { width:13px; height:13px; }
.profile-settings-wrapper .ps-form-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px; }
.profile-settings-wrapper .ps-form-row.ps-single { grid-template-columns:1fr; }
.profile-settings-wrapper .ps-field label { display:block; font-family:'Inter',sans-serif; font-size:14px; font-weight:500; line-height:1; color:#18181b; margin-bottom:6px; }
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
.profile-settings-wrapper .ps-btn { padding:9px 18px; font-family:'Inter',sans-serif; font-size:14px; font-weight:500; border-radius:8px; cursor:pointer; border:1px solid transparent; }
.profile-settings-wrapper .ps-btn-primary { background:#000; color:#fff; border-color:#000; }
.profile-settings-wrapper .ps-btn-primary:hover { background:#1a1a1a; }
.profile-settings-wrapper .ps-btn-primary:disabled { background:#c7c9d9; border-color:#c7c9d9; cursor:not-allowed; }
.profile-settings-wrapper .ps-btn-secondary { background:#fff; color:#1f2328; border-color:#d7dae0; }
.profile-settings-wrapper .ps-btn-secondary:hover { background:#f5f6f8; }
.profile-settings-wrapper .ps-footer-actions { display:flex; justify-content:flex-start; gap:10px; margin-top:4px; }
.profile-settings-wrapper .ps-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(20px); background:#1f2328; color:#fff; padding:10px 18px; border-radius:8px; font-size:13px; opacity:0; pointer-events:none; transition:opacity .2s,transform .2s; z-index:9999; }
.profile-settings-wrapper .ps-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
#account-settings { background:#fff; }

/* ï¿½"?ï¿½"? Dashboard Layout (corrected) ï¿½"?ï¿½"? */
:root{--card-bg:#ffffff;--text:#0f172a;--text-2:#475569;--text-3:#94a3b8;--border:#eef2f6;--border-2:#e2e8f0;--green:#045e1f;--shadow:0 1px 3px rgba(0,0,0,0.04);--text-main:var(--text);--text-secondary:var(--text-2);--text-muted:var(--text-3);--accent-blue:var(--green);--shadow-md:0 4px 6px rgba(0,0,0,0.05);}
.section-title{font-size:0.95rem;font-weight:700;color:var(--text);margin-bottom:0.85rem;display:flex;align-items:center;gap:0.5rem;font-family:'Inter',sans-serif;}
.title-wrapper{margin-bottom:1rem;}
.badge{background:var(--accent-blue);color:white;font-size:0.75rem;font-weight:600;padding:0.25rem 0.5rem;border-radius:6px;}
.card{background:var(--card-bg);border-radius:12px;box-shadow:var(--shadow);border:1px solid var(--border-2);overflow:hidden;display:flex;flex-direction:column;}
.card-header{padding:1.5rem 1.5rem 0 1.5rem;}
.card-body{padding:1.5rem;flex:1;}
.quick-actions{display:grid;grid-template-columns:repeat(4,1fr);gap:1.5rem;margin-bottom:2rem;}
.action-card{background:var(--card-bg);border-radius:12px;box-shadow:var(--shadow);border:1px solid var(--border-2);padding:1.25rem;display:flex;align-items:center;gap:1rem;cursor:pointer;transition:transform 0.2s,box-shadow 0.2s;}
.action-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);}
.action-icon{color:#000;width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.action-content h3{font-size:1rem;font-weight:700;color:var(--text);margin-bottom:0.25rem;font-family:'Inter',sans-serif;}
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
.calendar-header h3{font-size:0.88rem;font-weight:700;color:var(--text);margin:0;font-family:'Inter',sans-serif;}
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
.bk-page{max-width:960px;margin:0 auto;padding:32px 40px;font-family:'Inter',sans-serif;}
.bk-card2{background:#fff;border:1px solid #e4e4e7;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.04);padding:28px 32px;font-family:'Inter',sans-serif;}
@media (max-width:640px){ .bk-page{padding:16px 20px;} .bk-card2{padding:20px 18px;} }
/* Two-column body: LEFT = calendar, RIGHT = pet/service/time */
.bk-heading{font-family:'Inter',sans-serif;}
.bk-heading-title{font-family:'Inter',sans-serif;font-size:18px;font-weight:600;color:#18181b;letter-spacing:-0.025em;line-height:1;margin:0;}
.bk-heading-sub{font-family:'Inter',sans-serif;font-size:14px;color:#71717a;line-height:1.5;margin:6px 0 0;}
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
.bk-petprofile-name{font-family:'Inter',sans-serif;font-size:18px;font-weight:700;color:#18181b;letter-spacing:-0.02em;margin:0;text-transform:capitalize;}
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
/* Calendar: match the staff schedule preview */
.bk-cal-area{padding:0;max-width:390px;width:100%;}
/* Booking primary (teal) - defined globally because the white card wrapper was removed */
:root{--bk-primary:#0d9488;--bk-primary-hover:#0f766e;--bk-primary-soft:#ccfbf1;--bk-fg:#18181b;--bk-muted:#71717a;--bk-border:#e4e4e7;}
.bk-cal{border:1px solid #e4e4e7;border-radius:12px;padding:0;background:#fff;overflow:hidden;}
.bk-cal-nav{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0;padding:12px 14px 8px;}
.bk-month-nav{display:flex;align-items:center;gap:8px;min-width:0;}
.bk-cal-controls{display:flex;align-items:center;gap:4px;flex:none;}
.bk-cal-title{font-family:inherit;font-size:16px;font-weight:600;color:#18181b;min-width:0;text-align:left;white-space:nowrap;}
.bk-cal-btn{width:32px;height:32px;flex:none;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:none;background:transparent;color:#71717a;cursor:pointer;transition:background 0.15s,color 0.15s;font-size:18px;}
.bk-cal-btn:hover{background:#f4f4f5;color:#18181b;}
.bk-cal-btn svg{width:18px;height:18px;}
.bk-cal-today{border:none;background:transparent;color:#18181b;font-family:inherit;font-size:14px;font-weight:500;padding:6px 8px;border-radius:4px;cursor:pointer;}
.bk-cal-today:hover{background:#f4f4f5;}
.bk-weekdays{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));margin:0 14px;}
.bk-weekdays span{text-align:center;font-size:12px;font-weight:600;color:#71717a;height:34px;line-height:34px;text-transform:uppercase;letter-spacing:.04em;}
.bk-days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:1px;margin:0 14px 14px;background:#e4e4e7;border:1px solid #e4e4e7;border-radius:6px;overflow:hidden;}
.bk-day{position:relative;display:flex;align-items:center;justify-content:center;width:100%;height:auto;aspect-ratio:1/1;max-width:none;margin:0;border:0;border-radius:0;font-family:inherit;font-size:14px;font-weight:500;color:#18181b;background:#fff;cursor:pointer;transition:background 0.12s,color 0.12s;}
.bk-day:hover:not(:disabled){background:#f4f4f5;color:#18181b;}
.bk-day.bk-day-today{font-weight:700;}
.bk-day.bk-day-selected{background:#f0fdf4;color:#166534;font-weight:600;box-shadow:inset 0 0 0 2px #0e8131;}
.bk-day.bk-day-disabled{color:#a1a1aa;cursor:not-allowed;background:#f1f1f2;opacity:1;}
.bk-day.bk-day-dim{color:#a1a1aa;background:#f1f1f2;cursor:default;pointer-events:none;}
.bk-day.bk-day-custom:not(.bk-day-disabled)::after{content:'';position:absolute;bottom:4px;width:4px;height:4px;border-radius:50%;background:#0e8131;}
.bk-day.bk-day-selected.bk-day-custom::after{background:#166534;}
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
.bk-confirm-section{display:flex;flex-direction:column;gap:8px;}
.bk-confirm-summary{display:flex;align-items:center;gap:12px;padding:0;}
.bk-confirm-avatar{width:64px;height:64px;border-radius:8px;background:linear-gradient(135deg,#045e1f,#043012);color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;overflow:hidden;flex-shrink:0;}
.bk-confirm-avatar img{width:100%;height:100%;object-fit:cover;}
.bk-confirm-summary h4{font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:600;color:#18181b;margin:0;text-transform:capitalize;}
.bk-confirm-summary p{font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:400;color:#71717a;margin:2px 0 0;text-transform:capitalize;}
.bk-confirm-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 16px;border:0;border-radius:0;background:transparent;padding:0;}
.bk-detail-row{display:flex;flex-direction:column;align-items:flex-start;justify-content:flex-start;min-width:0;padding:0;border:0;gap:3px;}
.bk-detail-row span{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;line-height:1.4;color:#71717a;}
.bk-detail-row strong{font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;line-height:1.4;color:#18181b;text-align:left;max-width:100%;overflow-wrap:anywhere;}
/* Confirm Booking dialog (matches Register a Pet shadcn dialog) */
#confirmBookingModal.modal { background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
#confirmBookingModal .sd-dialog { width:100%; display:flex; justify-content:center; }
#confirmBookingModal .sd-dialog-content { position:relative; box-sizing:border-box; width:100%; max-width:520px; background:#ffffff; border:1px solid #e4e4e7; border-radius:8px; padding:20px; display:flex; flex-direction:column; gap:14px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); animation:modalIn 0.2s ease; max-height:90vh; overflow-y:auto; }
#confirmBookingModal .sd-dialog-close { position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all 0.15s ease; }
#confirmBookingModal .sd-dialog-close:hover { background:#f4f4f5; color:#18181b; }
#confirmBookingModal .sd-dialog-header { display:flex; flex-direction:column; gap:6px; text-align:left; font-family:'Inter','Segoe UI',sans-serif; }
#confirmBookingModal .sd-dialog-title { margin:0; font-family:'Inter',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
#confirmBookingModal .sd-dialog-description { margin:0; font-size:14px; color:#71717a; line-height:1.5; }
#confirmBookingModal .sd-field-group { display:flex; flex-direction:column; gap:18px; }
#confirmBookingModal .bk-confirm-section > .sd-label { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; line-height:1.4; color:#18181b; margin:0; }
#confirmBookingModal .sd-dialog-footer { display:flex; flex-direction:row-reverse; justify-content:flex-end; gap:8px; }
#confirmBookingModal .sd-label { font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:600; line-height:1.4; color:#71717a; margin-bottom:8px; }
@media (max-width:600px) {
  #confirmBookingModal .sd-dialog-content { padding:16px; }
  .bk-confirm-details { column-gap:12px; }
}
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
.sender-info h4{font-size:0.95rem;font-weight:700;color:var(--text);font-family:'Inter',sans-serif;}
.sender-info p{font-size:0.85rem;color:var(--text-3);margin:0 0 2px;}
.message-subject{font-size:0.82rem;font-weight:600;color:var(--text-2);}
.message-type-label{font-size:0.75rem;font-weight:600;color:var(--green);text-transform:uppercase;letter-spacing:0.04em;}
.message-preview{font-size:0.9rem;color:var(--text-2);line-height:1.45;}
.message-preview a{color:var(--accent-blue);text-decoration:none;}
.insights-card{background:var(--card-bg);border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,0.04);border:1px solid var(--border-2);display:flex;flex-direction:column;flex:1;}
.insights-header{padding:1.25rem 1.25rem 1rem;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border-2);}
.insights-title{font-size:0.95rem;font-weight:700;color:var(--text);margin:0;font-family:'Inter',sans-serif;}
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


/* ï¿½"?ï¿½"? Overflow guard: prevent horizontal scroll on any screen size ï¿½"?ï¿½"? */
.main-content,#mainContent,.dashboard-grid,.dash-main,.dash-sidebar{max-width:100%;overflow-x:hidden;box-sizing:border-box;}
.dashboard-grid>*,.dash-main>*,.dash-sidebar>*,.top-row>*{min-width:0;}

/* ï¿½"?ï¿½"? Responsive breakpoints ï¿½"?ï¿½"? */
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
<style>
  /* ========== RESIDENT DASHBOARD REWORK - SCOPED STYLES ========== */
  .resident-rework{--primary-green:#0B4D26;--primary-green-hover:#07381B;--primary-green-light:#EBF7EE;--primary-green-soft:#D1F0DA;--accent-green:#107C41;--bg-body:#FFFFFF;--bg-card:#FFFFFF;--bg-subtle:#F9FBF9;--bg-hover:#F1F5F1;--border-color:#E6ECE6;--border-light:#F0F4F0;--border-dark:#D4DDD4;--text-main:#111827;--text-secondary:#4B5563;--text-muted:#6B7280;--text-faint:#9CA3AF;--badge-green-bg:#ECFDF3;--badge-green-text:#027A48;--badge-green-border:#D1FADF;--badge-amber-bg:#FEF0C7;--badge-amber-text:#B54708;--badge-amber-border:#FEDF89;--badge-blue-bg:#E0F2FE;--badge-blue-text:#0284C7;--badge-blue-border:#BAE6FD;--badge-purple-bg:#F4EBFF;--badge-purple-text:#6941C6;--badge-purple-border:#E9D7FE;--radius-sm:8px;--radius-md:12px;--radius-lg:16px;--radius-xl:20px;--radius-full:9999px;--shadow-sm:0 1px 2px 0 rgba(16,24,40,0.04);--shadow-card:0 1px 3px 0 rgba(16,24,40,0.05),0 1px 2px -1px rgba(16,24,40,0.04);--shadow-popover:0 12px 28px -4px rgba(15,23,42,0.12),0 4px 10px -2px rgba(15,23,42,0.05);font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;color:var(--text-main);line-height:1.5;-webkit-font-smoothing:antialiased}
  .resident-rework *,.resident-rework *::before,.resident-rework *::after{box-sizing:border-box;margin:0;padding:0}
  .resident-rework .dashboard-content{padding:24px 28px 60px;display:flex;flex-direction:column;gap:24px;width:100%;max-width:1440px;margin:0 auto}
  .resident-rework .greeting-banner{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap}
  .resident-rework .greeting-date{font-family:'Inter',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#71717a;margin-bottom:4px}
  .resident-rework .greeting-headline{font-family:'Inter',sans-serif;font-size:28px;font-weight:700;color:#18181b;letter-spacing:-0.025em;line-height:1.2}
  .resident-rework .greeting-subtext{font-family:'Inter',sans-serif;font-size:15px;color:#71717a;margin-top:4px}
  .resident-rework .greeting-actions{display:flex;align-items:center;gap:10px}
  .resident-rework .btn-secondary-white{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:36px;padding:0 16px;border-radius:6px;border:1px solid #e4e4e7;background:#ffffff;color:#18181b;font-family:'Inter',sans-serif;font-size:14px;font-weight:500;line-height:1;white-space:nowrap;cursor:pointer;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);transition:background 0.15s ease,border-color 0.15s ease}
  .resident-rework .btn-secondary-white:hover{background:#f4f4f5;border-color:#d4d4d8}
  .resident-rework .action-dropdown-wrapper{position:relative}
  .resident-rework .btn-action-primary{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:36px;padding:0 16px;border-radius:6px;border:1px solid transparent;background:#18181b;color:#fafafa;font-family:'Inter',sans-serif;font-size:14px;font-weight:500;line-height:1;white-space:nowrap;cursor:pointer;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);transition:background 0.15s ease}
  .resident-rework .btn-action-primary:hover{background:#27272a}
  .resident-rework .action-dropdown-menu{position:absolute;right:0;top:calc(100% + 8px);background:#FFF;border:1px solid #e4e4e7;border-radius:8px;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1),0 4px 6px -4px rgba(0,0,0,0.1);min-width:200px;padding:4px;display:flex;flex-direction:column;gap:2px;z-index:60;opacity:0;visibility:hidden;transform:translateY(-8px);transition:all 0.2s cubic-bezier(0.16,1,0.3,1)}
  .resident-rework .action-dropdown-menu.show{opacity:1;visibility:visible;transform:translateY(0)}
  .resident-rework .action-dropdown-item{display:flex;align-items:center;justify-content:flex-start;gap:8px;padding:8px 16px;border-radius:6px;font-family:'Inter',sans-serif;font-size:14px;font-weight:500;color:#18181b;text-align:left;transition:background 0.15s ease;width:100%;background:none;border:none;cursor:pointer}
  .resident-rework .action-dropdown-item > svg{flex:0 0 16px;}
  .resident-rework .action-dropdown-item > span{flex:1;min-width:0;text-align:left;}
  .resident-rework .action-dropdown-item:hover{background:#f4f4f5}
  .resident-rework .metrics-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
  .resident-rework .metric-card{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:18px 20px;background:#FFFFFF;border:1px solid var(--border-color);border-radius:var(--radius-lg);box-shadow:var(--shadow-card);font-family:'Inter',sans-serif;cursor:default;transition:all 0.2s ease}
  .resident-rework .metric-card:hover{border-color:var(--border-dark);box-shadow:0 4px 12px -2px rgba(0,0,0,0.05),0 2px 4px -1px rgba(0,0,0,0.03)}
  .resident-rework .metric-icon-box{display:none}
  .resident-rework .icon-box-green{background-color:#ECFDF3;color:#0B4D26}
  .resident-rework .icon-box-blue{background-color:#EFF8FF;color:#0284C7}
  .resident-rework .icon-box-purple{background-color:#F9F5FF;color:#7C3AED}
  .resident-rework .icon-box-amber{background-color:#FFFBEB;color:#B45309}
  .resident-rework .icon-box-amber{background-color:#FEF6EE;color:#D97706}
  .resident-rework .metric-info{display:flex;flex-direction:column;gap:2px}
  .resident-rework .metric-label{font-family:'Inter',sans-serif;font-size:14px;font-weight:500;color:#18181b;text-transform:uppercase;letter-spacing:0.03em;margin:0 0 6px}
  .resident-rework .metric-value{font-family:'Inter',sans-serif;font-size:26px;font-weight:600;color:#18181b;letter-spacing:-0.02em;line-height:1;margin:0 0 6px}
  .resident-rework .metric-subtext{font-family:'Inter',sans-serif;font-size:14px;color:#71717a}
  .resident-rework .dashboard-workflow-layout{display:grid;grid-template-columns:1fr 1fr;gap:22px;align-items:start}
  .resident-rework .primary-vertical-column,.resident-rework .secondary-vertical-column{display:flex;flex-direction:column;gap:22px;align-self:start}
  .resident-rework .primary-vertical-column>.card-box:first-child{min-height:408px}
  .resident-rework .primary-vertical-column>.card-box:nth-child(2){min-height:413px}
  .resident-rework .secondary-vertical-column>.card-box:first-child{min-height:413px}
  .resident-rework .secondary-vertical-column>.card-box:nth-child(2){min-height:413px}
  .resident-rework .card-box{background:var(--bg-card);border:1px solid var(--border-color);border-radius:var(--radius-lg);box-shadow:var(--shadow-card);padding:22px 24px;display:flex;flex-direction:column;position:relative}
  .resident-rework .card-header-bar{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px}
  .resident-rework .card-meta-tag{font-family:'Inter',sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#71717a;margin-bottom:3px}
  .resident-rework .card-title-text{font-family:'Inter',sans-serif;font-size:16px;font-weight:600;color:#18181b;letter-spacing:-0.025em;line-height:1.25}
  .resident-rework .card-action-link{display:inline-flex;align-items:center;gap:4px;font-family:'Inter',sans-serif;font-size:13px;font-weight:500;color:#18181b;transition:gap 0.15s ease}
  .resident-rework .card-action-link:hover{color:#18181b;gap:7px}
  .resident-rework .appointment-items-list{display:flex;flex-direction:column;gap:8px;margin-bottom:12px}
  .resident-rework .appointment-item-row{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-radius:12px;background:#FFF;border:1px solid var(--border-color);transition:all 0.15s ease;cursor:pointer}
  .resident-rework .appointment-item-row.active-visit{border-left:4px solid var(--primary-green);background-color:#F8FAF8}
  .resident-rework .appointment-time-col{display:flex;align-items:center;gap:8px;min-width:90px;font-family:'Inter',sans-serif;font-size:13px;font-weight:500;color:#18181b}
  .resident-rework .status-dot-indicator{width:7px;height:7px;border-radius:50%;flex-shrink:0}
  .resident-rework .dot-green{background-color:#12B76A}
  .resident-rework .dot-amber{background-color:#F79009}
  .resident-rework .dot-blue{background-color:#0BA5EC}
  .resident-rework .appointment-details-col{flex:1;display:flex;flex-direction:column;padding:0 12px}
  .resident-rework .appointment-pet-name{font-family:'Inter',sans-serif;font-size:14px;font-weight:500;color:#18181b}
  .resident-rework .appointment-reason-meta{font-family:'Inter',sans-serif;font-size:13px;color:#71717a}
  .resident-rework .status-pill-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:4px;font-family:'Inter',sans-serif;font-size:12px;font-weight:500;white-space:nowrap;line-height:1.4;border:1px solid #e4e4e7;flex-shrink:0}
  .resident-rework .badge-pending{background-color:#fef3c7;color:#92400e;border-color:#fde68a}
  .resident-rework .badge-reviewing{background-color:#dbeafe;color:#1d4ed8;border-color:#bfdbfe}
  .resident-rework .badge-confirmed{background-color:#dcebe0;color:#045e1f;border-color:#bbf7d0}
  .resident-rework .badge-scheduled{background-color:#0E8131;color:#ffffff;border-color:#0E8131}
  .resident-rework .badge-completed{background-color:#dbeafe;color:#1e40af;border-color:#bfdbfe}
  .resident-rework .appointment-chevron{color:var(--text-faint);margin-left:8px;flex-shrink:0}
  .resident-rework .card-bottom-link-center{text-align:center;padding-top:4px;margin-top:auto}
  .resident-rework .pets-list-rework{display:flex;flex-direction:column}
  .resident-rework .pet-row-rework{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #f4f4f5;cursor:pointer;transition:background 0.15s ease}
  .resident-rework .pet-row-rework:last-child{border-bottom:none}
  .resident-rework .pet-row-rework:hover{background:#f9fafb}
  .resident-rework .pet-avatar-rework{width:56px;height:56px;border-radius:50%;overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:#e8f5ee;color:#045e1f;font-size:20px;font-weight:700}
  .resident-rework .pet-avatar-rework img{width:100%;height:100%;object-fit:cover}
  .resident-rework .pet-info-rework{flex:1;display:flex;flex-direction:column;gap:2px;min-width:0}
  .resident-rework .pet-name-rework{font-family:'Inter',sans-serif;font-size:14px;font-weight:600;color:#18181b}
  .resident-rework .pet-meta-rework{font-family:'Inter',sans-serif;font-size:13px;font-weight:400;color:#71717a}
  .resident-rework .pet-vax-pill-rework{font-family:'Inter',sans-serif;font-size:12px;font-weight:500;padding:3px 10px;border-radius:4px;border:1px solid;flex-shrink:0}
  .resident-rework .need-help-footer{display:flex;justify-content:center;margin-top:40px}
  .resident-rework .next-visit-hero-card{background:#F4FBF6;border:1px solid #E1F2E7;border-radius:14px;padding:16px 18px;display:flex;align-items:center;gap:14px;margin-bottom:16px}
  .resident-rework .next-visit-pet-avatar{width:52px;height:52px;border-radius:50%;overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:#ECFDF3;color:#0B4D26;font-size:20px;font-weight:700}
  .resident-rework .next-visit-pet-avatar img{width:100%;height:100%;object-fit:cover}
  .resident-rework .date-big-badge{display:flex;flex-direction:column;align-items:center;justify-content:center;background:#FFF;border:1px solid #C7EBD2;border-radius:12px;padding:10px 14px;min-width:62px;flex-shrink:0}
  .resident-rework .date-big-num{font-family:'Inter',sans-serif;font-size:26px;font-weight:600;color:var(--primary-green);line-height:1}
  .resident-rework .date-big-month{font-family:'Inter',sans-serif;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:#71717a;margin-top:3px}
  .resident-rework .next-visit-hero-info{flex:1;display:flex;flex-direction:column;gap:4px}
  .resident-rework .next-visit-title{font-family:'Inter',sans-serif;font-size:15px;font-weight:600;color:#18181b;line-height:1.25}
  .resident-rework .next-visit-pet{font-family:'Inter',sans-serif;font-size:13px;color:#71717a;font-weight:400}
  .resident-rework .visit-details-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px;padding:4px 0}
  .resident-rework .visit-detail-item{display:flex;align-items:flex-start;gap:8px}
  .resident-rework .visit-detail-item svg{color:var(--text-muted);width:16px;height:16px;flex-shrink:0;margin-top:2px}
  .resident-rework .detail-item-meta{display:flex;flex-direction:column}
  .resident-rework .detail-item-label{font-family:'Inter',sans-serif;font-size:12px;color:#71717a}
  .resident-rework .detail-item-value{font-family:'Inter',sans-serif;font-size:13px;font-weight:500;color:#18181b}
  .resident-rework .next-step-callout{display:flex;align-items:center;gap:10px;margin-bottom:16px}
  .resident-rework .next-step-check-circle{width:24px;height:24px;border-radius:50%;background:#D1FADF;color:#027A48;display:flex;align-items:center;justify-content:center;flex-shrink:0}
  .resident-rework .next-step-text-group{display:flex;flex-direction:column}
  .resident-rework .next-step-label{font-family:'Inter',sans-serif;font-size:12px;font-weight:600;color:#71717a;text-transform:uppercase;letter-spacing:0.04em}
  .resident-rework .next-step-instruction{font-family:'Inter',sans-serif;font-size:13px;font-weight:500;color:#18181b}
  .resident-rework .visit-action-buttons{display:flex;gap:10px;justify-content:center}
  .resident-rework .visit-action-buttons button{flex:0 1 auto;min-width:0}
  .resident-rework .btn-primary-green{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:36px;padding:0 16px;border-radius:6px;border:1px solid transparent;background:#18181b;color:#fafafa;font-family:'Inter',sans-serif;font-size:14px;font-weight:500;line-height:1;white-space:nowrap;cursor:pointer;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);transition:background 0.15s ease}
  .resident-rework .btn-primary-green:hover{background:#27272a}
  .resident-rework .btn-full{width:100%;justify-content:center}
  .resident-rework .reports-cards-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-top:12px;margin-bottom:14px}
  .resident-rework .report-item-box{background:#FFF;border:1px solid var(--border-color);border-radius:12px;padding:14px 16px;display:flex;flex-direction:column;gap:8px;transition:all 0.15s ease}
  .resident-rework .report-item-box:hover{border-color:var(--border-dark);box-shadow:var(--shadow-sm)}
  .resident-rework .report-header-row{display:flex;align-items:center;justify-content:space-between}
  .resident-rework .report-id-code{font-family:'Inter',sans-serif;font-size:12px;font-weight:500;color:#71717a}
  .resident-rework .report-title-heading{font-family:'Inter',sans-serif;font-size:14px;font-weight:600;color:#18181b;line-height:1.3}
  .resident-rework .report-sub-meta{display:flex;align-items:center;justify-content:space-between;font-family:'Inter',sans-serif;font-size:12px;color:#71717a}
  .resident-rework .report-progress-wrapper{display:flex;flex-direction:column;gap:4px}
  .resident-rework .progress-header{display:flex;justify-content:space-between;font-family:'Inter',sans-serif;font-size:13px;font-weight:500}
  .resident-rework .progress-track{width:100%;height:8px;background:#F1F5F9;overflow:hidden}
  .resident-rework .progress-bar-fill{height:100%;transition:width 0.4s ease}
  .resident-rework .fill-blue{background-color:#1A1A1A}
  .resident-rework .fill-purple{background-color:#1A1A1A}
  .resident-rework .report-link-footer{font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:500;color:#18181b;margin-top:3px;transition:color 0.15s ease}
  .resident-rework .report-link-footer:hover{color:var(--primary-green)}
  .resident-rework .submit-report-cta{display:inline-flex;align-items:center;gap:5px;font-family:'Inter',sans-serif;font-size:13px;font-weight:500;color:var(--primary-green);cursor:pointer;margin-top:3px;align-self:flex-start;transition:all 0.15s ease;background:none;border:none;padding:0}
  .resident-rework .submit-report-cta:hover{color:var(--primary-green-hover);transform:translateX(2px)}
  .resident-rework .announcements-list{display:flex;flex-direction:column;gap:14px;margin-top:4px}
  .resident-rework .announcement-item-row{display:flex;align-items:flex-start;gap:12px;padding-bottom:12px;border-bottom:1px solid var(--border-light)}
  .resident-rework .announcement-item-row:last-child{border-bottom:none;padding-bottom:0}
  .resident-rework .announcement-icon-badge{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0}
  .resident-rework .badge-icon-green{background-color:#ECFDF3;color:#027A48}
  .resident-rework .badge-icon-blue{background-color:#EFF8FF;color:#0284C7}
  .resident-rework .badge-icon-purple{background-color:#F4EBFF;color:#6941C6}
  .resident-rework .announcement-body{display:flex;flex-direction:column;gap:2px}
  .resident-rework .announcement-tag-label{font-family:'Inter',sans-serif;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em}
  .resident-rework .tag-green{color:#027A48}
  .resident-rework .tag-blue{color:#0284C7}
  .resident-rework .tag-purple{color:#6941C6}
  .resident-rework .announcement-headline{font-family:'Inter',sans-serif;font-size:14px;font-weight:600;color:#18181b;line-height:1.3}
  .resident-rework .announcement-snippet{font-family:'Inter',sans-serif;font-size:13px;color:#71717a;line-height:1.5}
  .resident-rework .announcement-timestamp{font-family:'Inter',sans-serif;font-size:12px;color:#71717a;margin-top:2px}
  .resident-rework .need-help-banner-card{background:#18181b;border:1px solid #18181b;border-radius:0;padding:24px;color:#FFF;display:inline-flex;flex-direction:column;align-items:center;gap:8px;box-shadow:0 4px 16px rgba(24,24,27,0.18);text-align:center}
  .resident-rework .help-icon-badge{width:38px;height:38px;border-radius:12px;background:rgba(255,255,255,0.12);display:flex;align-items:center;justify-content:center;color:#86EFAC}
  .resident-rework .help-tag-label{font-family:'Inter',sans-serif;font-size:11px;font-weight:600;letter-spacing:0.06em;text-transform:uppercase;color:#FFF}
  .resident-rework .help-headline{font-family:'Inter',sans-serif;font-size:17px;font-weight:600;color:#FFF;line-height:1.25}
  .resident-rework .help-subtext{font-family:'Inter',sans-serif;font-size:13px;color:#FFF;line-height:1.45}
  .resident-rework .help-cta-link{display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:700;color:#FFF;margin-top:3px;transition:gap 0.15s ease}
  .resident-rework .help-cta-link:hover{color:#FFF;gap:8px}
  .resident-rework .pets-open-section{display:flex;flex-direction:column;gap:14px;padding-top:10px;border-top:1px solid var(--border-light)}
  .resident-rework .section-open-header{display:flex;align-items:flex-start;justify-content:space-between}
  .resident-rework .pets-cards-flow{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
  .resident-rework .pet-photo-item{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:14px;background:#FFF;cursor:pointer;transition:all 0.2s ease;position:relative}
  .resident-rework .pet-photo-item:hover{background:#F8FAF8;transform:translateY(-2px)}
  .resident-rework .pet-photo-frame{position:relative;width:60px;height:60px;border-radius:14px;overflow:hidden;flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,0.08);border:2px solid #FFF}
  .resident-rework .pet-real-photo{width:100%;height:100%;object-fit:cover;display:block;transition:transform 0.25s ease}
  .resident-rework .pet-photo-item:hover .pet-real-photo{transform:scale(1.06)}
  .resident-rework .pet-species-badge{position:absolute;bottom:2px;right:2px;background:rgba(15,23,42,0.75);color:#FFF;font-family:'Inter',sans-serif;font-size:9px;font-weight:600;padding:1px 4px;border-radius:3px;letter-spacing:0.03em}
  .resident-rework .pet-photo-initial{width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#E8F5EE;color:var(--primary-green);font-size:20px;font-weight:800}
  .resident-rework .pet-profile-meta{display:flex;flex-direction:column;gap:2px;min-width:0}
  .resident-rework .pet-display-name{font-family:'Inter',sans-serif;font-size:14px;font-weight:600;color:#18181b;line-height:1.2}
  .resident-rework .pet-display-breed{font-family:'Inter',sans-serif;font-size:12px;color:#71717a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .resident-rework .pet-status-pill{font-family:'Inter',sans-serif;font-size:11px;font-weight:500;color:#027A48;background:#ECFDF3;padding:1px 7px;border-radius:var(--radius-full);align-self:flex-start;margin-top:1px;border:1px solid #D1FADF}
  .resident-rework .add-pet-open-btn{display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:14px;border:1.5px dashed #CBD5E1;background:#FAFCFA;cursor:pointer;transition:all 0.2s ease}
  .resident-rework .add-pet-open-btn:hover{background:#F0FDF4;border-color:var(--primary-green);color:var(--primary-green);transform:translateY(-2px)}
  .resident-rework .add-pet-plus-circle{width:50px;height:50px;border-radius:14px;background:#EAF5ED;color:var(--primary-green);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all 0.2s ease}
  .resident-rework .add-pet-open-btn:hover .add-pet-plus-circle{background:var(--primary-green);color:#FFF}
  .resident-rework .add-pet-label{font-family:'Inter',sans-serif;font-size:13px;font-weight:500;color:#71717a}
  .resident-rework .add-pet-open-btn:hover .add-pet-label{color:var(--primary-green)}
  .resident-rework .toast-popup{position:fixed;bottom:24px;right:24px;background:#0F172A;color:#FFF;padding:12px 18px;border-radius:12px;font-size:13px;font-weight:600;display:flex;align-items:center;gap:10px;box-shadow:0 10px 25px -3px rgba(0,0,0,0.2);z-index:200;opacity:0;transform:translateY(20px);transition:all 0.25s cubic-bezier(0.16,1,0.3,1);pointer-events:none}
  .resident-rework .toast-popup.show{opacity:1;transform:translateY(0);pointer-events:auto}
  .resident-rework .toast-icon{color:#10B981;flex-shrink:0}
  .resident-rework .rework-empty{padding:28px 0;text-align:center;color:var(--text-muted);font-size:12px}
  @media(max-width:1200px){.resident-rework .dashboard-workflow-layout{grid-template-columns:1fr}.resident-rework .pets-cards-flow{grid-template-columns:repeat(2,1fr)}}
  @media(max-width:860px){.resident-rework .dashboard-content{padding:16px 12px 48px}.resident-rework .metrics-grid{grid-template-columns:1fr}.resident-rework .reports-cards-grid{grid-template-columns:1fr}.resident-rework .pets-cards-flow{grid-template-columns:1fr}}
</style>
<link rel="stylesheet" href="assets/css/resident-overview.css" />
<style>
  html, body.dashboard, body.dashboard .main-content { background: #ffffff !important; }
</style>
</head>
<body class="dashboard resident-portal" data-realtime-page="resident">

<?php include __DIR__ . '/../includes/resident-sidebar.php'; ?>

<!-- ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½
     MAIN CONTENT
ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ -->
<main class="main-content" id="mainContent">

<?php
$portalToasts = array_filter([$petSuccess, $reportSuccess, $appointmentSuccess, $profileSuccess, $passwordSuccess]);
include __DIR__ . '/../includes/portal-toast.php';
?>

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
  <div id="report-submit-error" class="auth-alert auth-alert-error" style="margin: 1rem 0;">
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

  <!-- ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"? OVERVIEW / DASHBOARD ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"? -->
  <section id="overview" class="content-section active">

    <?php if (isset($_SESSION['login_notice'])): ?>
      <div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;color:#1e40af;font-size:0.9rem;">
        <i class="fas fa-info-circle"></i>
        <span><?php echo htmlspecialchars($_SESSION['login_notice']); unset($_SESSION['login_notice']); ?></span>
        <button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;color:#1e40af;cursor:pointer;font-size:1.1rem;">&times;</button>
      </div>
    <?php endif; ?>

     <div class="resident-rework">
       <div class="dashboard-content">

         <?php
         $greetingHour = (int)date('H');
         $greetingWord = ($greetingHour < 12) ? 'Good morning' : (($greetingHour < 18) ? 'Good afternoon' : 'Good evening');
         $firstAppt = null;
         if (!empty($todayAppts)) { $firstAppt = $todayAppts[0]; }
         elseif (!empty($upcomingAppts)) { $firstAppt = $upcomingAppts[0]; }
         ?>

         <section class="greeting-banner">
           <div>
             <div class="greeting-date" id="rework-live-date"><?php echo date('l, F j, Y'); ?></div>
             <h2 class="greeting-headline"><?php echo $greetingWord . ', ' . htmlspecialchars($firstName); ?>.</h2>
             <p class="greeting-subtext">Here's what needs your attention today.</p>
           </div>
            <div class="greeting-actions">
              <button class="btn-secondary-white" onclick="switchSection('calendar-booking')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span>View calendar</span>
              </button>
              <div class="action-dropdown-wrapper">
                <button class="btn-action-primary" id="rework-action-btn" onclick="reworkToggleDropdown(event)">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  <span>Action</span>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-left:2px"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="action-dropdown-menu" id="rework-action-menu">
                  <button class="action-dropdown-item" onclick="reworkOpenModal('addPetModal');reworkCloseDropdown();">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="4" cy="8" r="2"/><path d="M12 18c3 0 6-2 6-6 0-3-2-5-6-5s-6 2-6 5c0 4 3 6 6 6z"/></svg>
                    <span>Register a Pet</span>
                  </button>
                  <button class="action-dropdown-item" onclick="window.location.href='submit-report-page.php';reworkCloseDropdown();">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>
                    <span>Submit a Report</span>
                  </button>
                  <button class="action-dropdown-item" onclick="openBookApptModal();reworkCloseDropdown();">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                    <span>Schedule Appointment</span>
                  </button>
                </div>
              </div>
            </div>
         </section>

         <section class="metrics-grid">
           <div class="metric-card">
             <div class="metric-icon-box icon-box-green">
               <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
             </div>
             <div class="metric-info">
               <span class="metric-label">Upcoming appointments</span>
               <span class="metric-value"><?php echo $upcomingApptCount; ?></span>
               <span class="metric-subtext"><?php echo $upcomingApptCount > 0 ? 'Scheduled' : 'No appointments'; ?></span>
             </div>
           </div>
            <div class="metric-card">
              <div class="metric-icon-box icon-box-blue">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>
              </div>
              <div class="metric-info">
                <span class="metric-label">Reports submitted</span>
                <span class="metric-value"><?php echo count($reports); ?></span>
                <span class="metric-subtext">Total filed</span>
              </div>
            </div>
            <div class="metric-card">
              <div class="metric-icon-box icon-box-amber">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              </div>
              <div class="metric-info">
                <span class="metric-label">Pending reports</span>
                <span class="metric-value"><?php echo $activeCases; ?></span>
                <span class="metric-subtext">Awaiting review</span>
              </div>
            </div>
           <div class="metric-card">
             <div class="metric-icon-box icon-box-purple">
               <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
             </div>
             <div class="metric-info">
               <span class="metric-label">Active care plans</span>
               <span class="metric-value"><?php echo count($pets); ?></span>
               <span class="metric-subtext">Across <?php echo count($pets); ?> pet<?php echo count($pets) !== 1 ? 's' : ''; ?></span>
             </div>
           </div>

         </section>

         <div class="dashboard-workflow-layout">
           <div class="primary-vertical-column">

             <div class="card-box">
               <div class="card-header-bar">
                 <div>
                   <div class="card-meta-tag"><?php echo $upcomingApptCount; ?> appointment<?php echo $upcomingApptCount !== 1 ? 's' : ''; ?></div>
                   <h3 class="card-title-text">Upcoming Appointments</h3>
                 </div>
                  <a href="#" class="card-action-link" onclick="switchSection('calendar-booking');return false;">
                    <span>View calendar</span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                  </a>
                </div>
                <div class="appointment-items-list" id="rework-appointments-container">
                 <?php if (empty($todayAppts)): ?>
                   <div class="rework-empty">No upcoming appointments.</div>
                 <?php else: ?>
                   <?php foreach (array_slice($todayAppts, 0, 3) as $apt): ?>
                     <?php
                     $aptIsPending = strtolower($apt['status']) === 'pending';
                     $aptDotClass = $aptIsPending ? 'dot-amber' : 'dot-green';
                     $aptBadgeClass = $aptIsPending ? 'badge-pending' : (strtolower($apt['status']) === 'completed' ? 'badge-completed' : 'badge-scheduled');
                     $aptDay = date('d', strtotime($apt['preferred_date']));
                     $aptMonth = date('M Y', strtotime($apt['preferred_date']));
                     ?>
                     <div class="appointment-item-row" onclick="reworkSelectVisit(this,{apptId:<?php echo (int)$apt['appointment_id']; ?>,type:'<?php echo htmlspecialchars(addslashes($apt['appointment_type'])); ?>',petId:<?php echo (int)($apt['pet_id'] ?? 0); ?>,date:'<?php echo htmlspecialchars($apt['preferred_date']); ?>',timeRaw:'<?php echo htmlspecialchars($apt['preferred_time']); ?>',notes:'<?php echo htmlspecialchars(addslashes($apt['notes'] ?? '')); ?>',pet:'<?php echo htmlspecialchars(addslashes($apt['pet_name'])); ?>',service:'<?php echo htmlspecialchars(addslashes(ucfirst($apt['appointment_type']))); ?>',time:'<?php echo date('g:i A', strtotime($apt['preferred_time'])); ?>',status:'<?php echo ucfirst($apt['status']); ?>',day:'<?php echo $aptDay; ?>',month:'<?php echo $aptMonth; ?>',nextStep:'Bring your pet records'})">
                       <div class="appointment-time-col">
                         <span><?php echo date('g:i A', strtotime($apt['preferred_time'])); ?></span>
                         <span class="status-dot-indicator <?php echo $aptDotClass; ?>"></span>
                       </div>
                       <div class="appointment-details-col">
                         <span class="appointment-pet-name"><?php echo htmlspecialchars($apt['pet_name']); ?></span>
                         <span class="appointment-reason-meta"><?php echo htmlspecialchars(ucfirst($apt['appointment_type'])); ?> &bull; <?php echo $aptIsPending ? 'Awaiting confirmation' : 'Veterinary Services'; ?></span>
                       </div>
                        <span class="status-pill-badge <?php echo $aptBadgeClass; ?>"><?php echo ucfirst($apt['status']); ?></span>
                       <svg class="appointment-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                     </div>
                   <?php endforeach; ?>
                 <?php endif; ?>
                </div>
              </div>

             <div class="card-box">
               <div class="card-header-bar">
                 <div>
                   <div class="card-meta-tag">Service Requests</div>
                   <h3 class="card-title-text">My reports</h3>
                 </div>
                 <a href="#" class="card-action-link" onclick="switchSection('my-reports');return false;">
                   <span>View all</span>
                   <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                 </a>
               </div>
               <?php if (empty($reports)): ?>
                 <div class="rework-empty">No reports submitted yet.</div>
               <?php else: ?>
                 <div class="reports-cards-grid">
                   <?php foreach (array_slice($reports, 0, 4) as $r): ?>
                     <?php
                     $rStatus = strtolower($r['status']);
                      if ($rStatus === 'pending') { $rBadge = 'badge-pending'; $rBadgeText = 'Pending'; $rProgress = 20; $rBarColor = 'fill-blue'; }
                      elseif ($rStatus === 'validated') { $rBadge = 'badge-reviewing'; $rBadgeText = 'Reviewing'; $rProgress = 40; $rBarColor = 'fill-blue'; }
                      elseif ($rStatus === 'active') { $rBadge = 'badge-scheduled'; $rBadgeText = 'Active'; $rProgress = 70; $rBarColor = 'fill-purple'; }
                      else { $rBadge = 'badge-confirmed'; $rBadgeText = 'Resolved'; $rProgress = 100; $rBarColor = 'fill-blue'; }
                     ?>
                     <div class="report-item-box">
                       <div class="report-header-row">
                         <span class="report-id-code"><?php echo htmlspecialchars($r['reference_id']); ?></span>
                       <span class="status-pill-badge <?php echo $rBadge; ?>"><?php echo $rBadgeText; ?></span>
                       </div>
                       <h4 class="report-title-heading"><?php echo htmlspecialchars(ucfirst($r['report_type'])); ?></h4>
                        <div class="report-sub-meta">
                          <span><?php echo htmlspecialchars($r['location_address']); ?></span>
                          <span>Submitted <?php echo date('M d, Y', strtotime($r['created_at'])); ?></span>
                        </div>
                       <div class="report-progress-wrapper">
                         <div class="progress-header"><span>Progress</span><span><?php echo $rProgress; ?>%</span></div>
                         <div class="progress-track"><div class="progress-bar-fill <?php echo $rBarColor; ?>" style="width:<?php echo $rProgress; ?>%"></div></div>
                       </div>
                       <a href="#" class="report-link-footer" onclick="switchSection('my-reports');return false;">
                         <span>View report</span>
                         <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                       </a>
                     </div>
                   <?php endforeach; ?>
                 </div>
                <?php endif; ?>
              </div>

            </div>

            <div class="secondary-vertical-column">

             <div class="card-box">
               <div class="card-header-bar">
                 <div>
                   <div class="card-meta-tag">Next Appointment</div>
                   <h3 class="card-title-text">Your next visit</h3>
                 </div>
               </div>

               <?php if ($firstAppt): ?>
                 <div class="next-visit-hero-card">
                    <div class="date-big-badge">
                      <span class="date-big-num" id="rework-visit-day"><?php echo date('d', strtotime($firstAppt['preferred_date'])); ?></span>
                      <span class="date-big-month" id="rework-visit-month"><?php echo date('M Y', strtotime($firstAppt['preferred_date'])); ?></span>
                    </div>
                    <div class="next-visit-pet-avatar">
                      <?php if (!empty($firstAppt['pet_photo'])): ?>
                        <img src="<?php echo htmlspecialchars($firstAppt['pet_photo']); ?>" alt="">
                      <?php else: ?>
                        <span><?php echo strtoupper(substr($firstAppt['pet_name'], 0, 1)); ?></span>
                      <?php endif; ?>
                    </div>
                    <div class="next-visit-hero-info">
                      <span class="status-pill-badge <?php echo strtolower($firstAppt['status']) === 'pending' ? 'badge-pending' : 'badge-confirmed'; ?>" id="rework-visit-status" style="align-self:flex-start;margin-bottom:2px"><?php echo ucfirst($firstAppt['status']); ?></span>
                     <span class="next-visit-title" id="rework-visit-title"><?php echo htmlspecialchars(ucfirst($firstAppt['appointment_type'])); ?></span>
                     <span class="next-visit-pet" id="rework-visit-pet">For <?php echo htmlspecialchars($firstAppt['pet_name']); ?></span>
                   </div>
                 </div>

                 <div class="visit-details-row">
                   <div class="visit-detail-item">
                     <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                     <div class="detail-item-meta">
                       <span class="detail-item-label">Time</span>
                       <span class="detail-item-value" id="rework-visit-time"><?php echo date('g:i A', strtotime($firstAppt['preferred_time'])); ?></span>
                     </div>
                   </div>
                   <div class="visit-detail-item">
                     <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                     <div class="detail-item-meta">
                       <span class="detail-item-label">Pet</span>
                       <span class="detail-item-value"><?php echo htmlspecialchars($firstAppt['pet_name']); ?></span>
                     </div>
                   </div>
                   <div class="visit-detail-item">
                     <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                     <div class="detail-item-meta">
                       <span class="detail-item-label">Date</span>
                       <span class="detail-item-value"><?php echo date('M d, Y', strtotime($firstAppt['preferred_date'])); ?></span>
                     </div>
                   </div>
                 </div>

                 <div class="next-step-callout">
                    <div class="next-step-text-group">
                      <span class="next-step-label">Next step</span>
                      <span class="next-step-instruction" id="rework-visit-instruction">Bring your pet records</span>
                    </div>
                  </div>

                 <div class="visit-action-buttons" style="margin-top:auto">
                    <button class="btn-primary-green" id="rework-view-btn" data-appt-id="<?php echo (int)$firstAppt['appointment_id']; ?>" onclick="reworkViewAppointment()">View appointment</button>
                    <button class="btn-secondary-white" id="rework-reschedule-btn" data-appt-id="<?php echo (int)$firstAppt['appointment_id']; ?>" data-appt-type="<?php echo htmlspecialchars($firstAppt['appointment_type']); ?>" data-appt-pet="<?php echo (int)($firstAppt['pet_id'] ?? 0); ?>" data-appt-date="<?php echo htmlspecialchars($firstAppt['preferred_date']); ?>" data-appt-time="<?php echo htmlspecialchars($firstAppt['preferred_time']); ?>" data-appt-notes="<?php echo htmlspecialchars($firstAppt['notes'] ?? ''); ?>" onclick="reworkRescheduleVisit()">Reschedule</button>
                 </div>
               <?php else: ?>
                 <div class="rework-empty">No upcoming appointments.</div>
               <?php endif; ?>
             </div>

             <div class="card-box">
               <div class="card-header-bar">
                 <div>
                   <div class="card-meta-tag">Registered Pets</div>
                   <h3 class="card-title-text">My Pets</h3>
                 </div>
                 <a href="#" class="card-action-link" onclick="switchSection('my-pets');return false;">
                   <span>View all pets</span>
                   <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                 </a>
               </div>
               <?php if (empty($pets)): ?>
                 <div class="rework-empty">No pets registered yet.</div>
               <?php else: ?>
                 <div class="pets-list-rework">
                   <?php foreach (array_slice($pets, 0, 4) as $pet): ?>
                     <?php
                       $pVaxText = !empty($pet['vaccination_status']) ? $pet['vaccination_status'] : 'Not vaccinated';
                       $pVaxLower = strtolower($pVaxText);
                       if (in_array($pVaxLower, ['vaccinated', 'complete', 'fully vaccinated'])) { $pVaxBg = '#ECFDF3'; $pVaxColor = '#0B4D26'; $pVaxBorder = '#D1EEDB'; }
                       elseif ($pVaxLower === 'up to date') { $pVaxBg = '#ECFDF3'; $pVaxColor = '#0B4D26'; $pVaxBorder = '#D1EEDB'; }
                       elseif ($pVaxLower === 'due soon') { $pVaxBg = '#fef3c7'; $pVaxColor = '#92400e'; $pVaxBorder = '#fde68a'; }
                       else { $pVaxBg = '#fee2e2'; $pVaxColor = '#991b1b'; $pVaxBorder = '#fecaca'; }
                       $pPhoto = $pet['photo_url'] ?? '';
                       $pInitial = strtoupper(substr($pet['pet_name'], 0, 1));
                     ?>
                     <div class="pet-row-rework" onclick="window.location.href='pet-profile.php?id=<?php echo (int)$pet['pet_id']; ?>&ref=resident'">
                       <div class="pet-avatar-rework">
                         <?php if ($pPhoto): ?>
                           <img src="<?php echo htmlspecialchars($pPhoto); ?>" alt="">
                         <?php else: ?>
                           <span><?php echo $pInitial; ?></span>
                         <?php endif; ?>
                       </div>
                       <div class="pet-info-rework">
                         <span class="pet-name-rework"><?php echo htmlspecialchars($pet['pet_name']); ?></span>
                         <span class="pet-meta-rework"><?php echo ucfirst(htmlspecialchars($pet['pet_type'])); ?> &bull; <?php echo htmlspecialchars($pet['breed'] ?: '-'); ?> &bull; <?php echo ucfirst(htmlspecialchars($pet['gender'] ?: '-')); ?> &bull; <?php echo htmlspecialchars($pet['age'] ? $pet['age'].' year'.((int)$pet['age']!==1?'s':'') : '-'); ?></span>
                       </div>
                       <span class="pet-vax-pill-rework" style="background:<?php echo $pVaxBg; ?>;color:<?php echo $pVaxColor; ?>;border-color:<?php echo $pVaxBorder; ?>"><?php echo htmlspecialchars(ucfirst($pVaxText)); ?></span>
                       <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                     </div>
                   <?php endforeach; ?>
                 </div>
               <?php endif; ?>
             </div>

            </div>
          </div>

          <div class="need-help-footer">
            <div class="need-help-banner-card">
              <div>
                <span class="help-tag-label">Need Help?</span>
                <h3 class="help-headline">We're here for you and your pets.</h3>
                <p class="help-subtext">Contact the DCVS team for appointment or report assistance.</p>
              </div>
              <a href="#" class="help-cta-link" onclick="switchSection('account-settings');return false;">
                <span>Contact support</span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
              </a>
            </div>
          </div>



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
      <div class="action-card" onclick="window.location.href='submit-report-page.php'">
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
              <a href="submit-report-page.php" class="action">
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
                        elseif ($apptStatusStr === 'scheduled') { $statusPillColor = '#ffffff'; $statusPillBg = '#0E8131'; $statusPillBorder = '#0E8131'; }
                        elseif ($apptStatusStr === 'completed') { $statusPillColor = '#1e40af'; $statusPillBg = '#dbeafe'; $statusPillBorder = '#bfdbfe'; }
                        elseif ($apptStatusStr === 'resolved') { $statusPillColor = '#10B981'; $statusPillBg = '#ECFDF5'; $statusPillBorder = '#a7f3d0'; }
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

  <!-- ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"? MY PETS ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"? -->
  <section id="my-pets" class="content-section">
    <!-- Overview KPIs -->
    <div class="pets-kpi-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px;">
      <div class="pets-kpi-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;text-align:center;font-family:'Inter',sans-serif;">
        <p style="font-size:14px;font-weight:500;color:#18181b;margin:0 0 6px;text-transform:uppercase;letter-spacing:0.03em;">Total Pets</p>
        <p style="font-size:26px;font-weight:600;color:#18181b;letter-spacing:-0.02em;line-height:1;margin:0 0 6px;" id="sumTotal">0</p>
        <p style="font-size:14px;color:#71717a;margin:0;">Registered pets</p>
      </div>
      <div class="pets-kpi-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;text-align:center;font-family:'Inter',sans-serif;">
        <p style="font-size:14px;font-weight:500;color:#18181b;margin:0 0 6px;text-transform:uppercase;letter-spacing:0.03em;">Fully Vaccinated</p>
        <p style="font-size:26px;font-weight:600;color:#18181b;letter-spacing:-0.02em;line-height:1;margin:0 0 6px;" id="sumVaccinated">0</p>
        <p style="font-size:14px;color:#71717a;margin:0;">Up to date</p>
      </div>
      <div class="pets-kpi-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;text-align:center;font-family:'Inter',sans-serif;">
        <p style="font-size:14px;font-weight:500;color:#18181b;margin:0 0 6px;text-transform:uppercase;letter-spacing:0.03em;">Adopted Pets</p>
        <p style="font-size:26px;font-weight:600;color:#18181b;letter-spacing:-0.02em;line-height:1;margin:0 0 6px;" id="sumAdopted">0</p>
        <p style="font-size:14px;color:#71717a;margin:0;">Pets adopted</p>
      </div>
      <div class="pets-kpi-card" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;text-align:center;font-family:'Inter',sans-serif;">
        <p style="font-size:14px;font-weight:500;color:#18181b;margin:0 0 6px;text-transform:uppercase;letter-spacing:0.03em;">Needs Attention</p>
        <p style="font-size:26px;font-weight:600;color:#18181b;letter-spacing:-0.02em;line-height:1;margin:0 0 6px;" id="sumAttention">0</p>
        <p style="font-size:14px;color:#71717a;margin:0;">Pending vaccination</p>
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
            <th class="sd-th"><button type="button" class="th-sort" data-col="adopted" onclick="PET.sortBy('adopted')">Confirmed/Adopted Date<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="registered" onclick="PET.sortBy('registered')">Registered Date<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th" style="width:40px;"></th>
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
              <div class="contact-main contact-normal"><?php echo $pCatLabel; ?></div>
            </td>
            <td class="sd-td">
              <div class="contact-main contact-normal"><?php echo $pAdoptedDate ?: '-'; ?></div>
            </td>
            <td class="sd-td">
              <div class="contact-main contact-normal"><?php echo $pRegisteredDate; ?></div>
            </td>
            <td class="sd-td sd-td-action" onclick="event.stopPropagation()">
              <button class="sd-row-actions" title="More options" onclick="event.stopPropagation();petRowActions(event,<?php echo (int)$pet['pet_id']; ?>)"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div id="petPagination" class="appt-pagination dt-pagination"></div>
    <?php endif; ?>
  </section>

  <style>
        /* ?? My Pets (HubSpot CRM style) ?? */
    #my-pets{font-family:'Inter',sans-serif;}
    body.page-white{background:#ffffff;}

    /* sd buttons (shadcn) */
    .sd-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; height:36px; padding:0 16px; border-radius:6px; border:1px solid transparent; font-family:'Inter',sans-serif; font-size:14px; font-weight:500; line-height:1; white-space:nowrap; cursor:pointer; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); transition:background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease; }
    .sd-btn-default { background:#18181b; color:#fafafa; }
    .sd-btn-default:hover { background:#27272a; }
    .sd-btn-outline { background:#ffffff; border-color:#e4e4e7; color:#18181b; }
    .sd-btn-outline:hover { background:#f4f4f5; }
    .sd-btn-primary { background:#18181b; color:#fafafa; border-color:#18181b; }
    .sd-btn-primary:hover { background:#27272a; }
    .sd-btn-danger { background:#ffffff; border-color:#fecaca; color:#b91c1c; }
    .sd-btn-danger:hover { background:#fef2f2; }
    .sd-btn:disabled, .sd-btn[disabled] { opacity:0.5; cursor:not-allowed; pointer-events:none; }
    .sd-btn i { font-size:12px; }

    /* shadcn dialog (Register / Edit Pet) */
    #addPetModal.modal, #editPetModal.modal { background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
    #addPetModal .sd-dialog, #editPetModal .sd-dialog { width:100%; display:flex; justify-content:center; }
    #addPetModal .sd-dialog-content, #editPetModal .sd-dialog-content { position:relative; width:100%; max-width:560px; background:#ffffff; border:1px solid #e4e4e7; border-radius:8px; padding:24px; display:flex; flex-direction:column; gap:16px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); animation:modalIn 0.2s ease; max-height:90vh; overflow-y:auto; }
    #addPetModal .sd-dialog-close, #editPetModal .sd-dialog-close { position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all 0.15s ease; }
    #addPetModal .sd-dialog-close:hover, #editPetModal .sd-dialog-close:hover { background:#f4f4f5; color:#18181b; }
    #addPetModal .sd-dialog-header, #editPetModal .sd-dialog-header { display:flex; flex-direction:column; gap:6px; text-align:left; }
    #addPetModal .sd-dialog-title, #editPetModal .sd-dialog-title { margin:0; font-family:'Inter',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
    #addPetModal .sd-dialog-description, #editPetModal .sd-dialog-description { margin:0; font-size:14px; color:#71717a; line-height:1.5; }
    #addPetModal .sd-field-group, #editPetModal .sd-field-group { display:flex; flex-direction:column; gap:16px; }
    #addPetModal .sd-form-grid, #editPetModal .sd-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    #addPetModal .sd-field-full, #editPetModal .sd-field-full { grid-column:1 / -1; }
    #addPetModal .sd-field, #editPetModal .sd-field { display:flex; flex-direction:column; gap:6px; }
    #addPetModal .sd-label, #editPetModal .sd-label { font-family:'Inter',sans-serif; font-size:14px; font-weight:500; line-height:1; color:#18181b; }
    #addPetModal .sd-label .req, #editPetModal .sd-label .req { color:#dc2626; }
    #addPetModal .adoption-readonly-value { display:flex; align-items:center; width:100%; min-height:36px; margin:0; padding:0 12px; border:1px solid #e4e4e7; border-radius:6px; background:#fff; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); box-sizing:border-box; font-family:'Inter',sans-serif; font-size:14px; font-weight:400; line-height:1.4; color:#18181b; }
    #addPetModal .adoption-readonly-photo { width:100%; min-height:120px; margin:0; padding:8px; border:1px solid #e4e4e7; border-radius:6px; background:#fafafa; box-sizing:border-box; display:flex; align-items:center; justify-content:center; overflow:hidden; }
    #addPetModal .adoption-readonly-photo img { display:block; width:100%; max-height:220px; object-fit:contain; border-radius:4px; }
    #addPetModal .sd-input, #editPetModal .sd-input { width:100%; height:36px; padding:0 12px; font-family:'Inter',sans-serif; font-size:14px; color:#18181b; background:transparent; border:1px solid #e4e4e7; border-radius:6px; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); outline:none; transition:box-shadow 0.15s ease, border-color 0.15s ease; box-sizing:border-box; }
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
    .appt-search-input{height:36px;padding:0 12px 0 36px;width:280px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-family:'Inter',sans-serif;font-size:14px;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);transition:border-color .15s,box-shadow .15s;}
    .appt-search-input:focus{outline:none;border-color:#18181b;box-shadow:0 0 0 1px #18181b;background:#fff;}
    .appt-search-input::placeholder{color:#a1a1aa;}
    .appt-status-select{height:36px;padding:0 28px 0 12px;border:1px solid #e4e4e7;border-radius:6px;background-color:#fff;color:#18181b;font-family:'Inter',sans-serif;font-size:14px;font-weight:500;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);cursor:pointer;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;transition:border-color .15s,box-shadow .15s;}
    .appt-status-select:focus{outline:none;border-color:#18181b;box-shadow:0 0 0 1px #18181b;}

    /* ?? HubSpot-style table ?? */
    .appt-db-table-area{overflow:hidden;border:1px solid #e4e4e7;border-radius:8px;background:#fff;padding:0;}
    .appt-table{width:100%;border-collapse:collapse;color:#18181b;font-size:14px;}
    .appt-table thead th{background:#fafafa;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;letter-spacing:0;text-transform:none;color:#71717a;height:40px;padding:0 8px;border-bottom:1px solid #e4e4e7;text-align:left;vertical-align:middle;white-space:nowrap;user-select:none;}
    .appt-table thead th:first-child{border-top-left-radius:7px;}
    .appt-table thead th:last-child{border-top-right-radius:7px;}
    .appt-table thead th.sd-th-action{text-align:right;}
    .appt-table tbody tr{border-bottom:1px solid #f4f4f5;cursor:pointer;transition:background .1s;}
    .appt-table tbody tr:last-child{border-bottom:none;}
    .appt-table tbody tr:hover{background:#f9fafb;}
    .appt-table tbody tr.selected{background:#f4f4f5;}
    .appt-table tbody td{padding:10px 12px;font-size:14px;color:#18181b;vertical-align:middle;}
    .appt-table tbody td.sd-td-action{text-align:right;}

    /* ?? Sortable column headers ?? */
    .th-sort{display:inline-flex;align-items:center;gap:4px;height:28px;margin-left:-8px;padding:0 8px;border-radius:4px;background:transparent;border:none;font-size:14px;font-weight:500;color:#71717a;cursor:pointer;white-space:nowrap;font-family:inherit;letter-spacing:0;text-transform:none;transition:background .1s,color .1s;}
    .th-sort:hover{background:#e4e4e7;color:#18181b;}
    .th-sort.th-sorted{color:#18181b;}
    .th-sort-icon{width:14px;height:14px;color:#a1a1aa;flex-shrink:0;}
    .th-sorted .th-sort-icon{color:#18181b;}

    /* ?? Pet cell: avatar + underlined name ?? */
    .client-cell{display:flex;align-items:center;gap:10px;min-width:0;}
    .avatar{width:36px;height:36px;border-radius:50%;background:#f4f4f5;color:#71717a;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;overflow:hidden;flex-shrink:0;border:1px solid #e4e4e7;}
    .avatar img{width:100%;height:100%;object-fit:cover;}
    .appt-pet-avatar{width:36px;height:36px;border-radius:50%;background:#f0fdf4;color:#0e8131;display:inline-flex;align-items:center;justify-content:center;font-size:15px;overflow:hidden;flex-shrink:0;border:1px solid #bbf7d0;}
    .appt-pet-avatar img{width:100%;height:100%;object-fit:cover;}
    .client-name{font-family:'Inter',sans-serif;font-weight:500;color:#18181b;font-size:14px;white-space:nowrap;text-decoration:underline;text-decoration-color:transparent;text-underline-offset:2px;transition:text-decoration-color .15s;}
    .sd-tr:hover .client-name{text-decoration-color:#a1a1aa;}
    .client-id{font-family:'Inter',sans-serif;font-size:12px;font-weight:400;color:#71717a;margin-top:2px;}
    .contact-main{font-family:'Inter',sans-serif;font-size:14px;font-weight:500;color:#18181b;white-space:nowrap;}
    .contact-main.contact-normal{font-weight:500;}
    .appt-status-pill{display:inline-flex;align-items:center;padding:3px 10px;border-radius:4px;font-family:'Inter',sans-serif;font-size:12px;font-weight:500;white-space:nowrap;line-height:1.4;border:1px solid #e4e4e7;}

    /* ?? Pet table cells ?? */
    .pet-avatar-box{width:40px;height:40px;border-radius:50%;background:#f4f4f5;color:#71717a;display:inline-flex;align-items:center;justify-content:center;font-size:15px;font-weight:600;overflow:hidden;flex-shrink:0;border:1px solid #e4e4e7;}
    .pet-avatar-box img{width:100%;height:100%;object-fit:cover;}
    .pet-cell-main{display:flex;flex-direction:column;min-width:0;}
    .pet-id-label{font-family:'Inter',sans-serif;font-size:12px;font-weight:400;color:#71717a;margin-top:2px;}
    .pet-cat-cell{display:flex;flex-direction:column;align-items:flex-start;gap:3px;}
    .pet-cat-tag{display:inline-flex;align-items:center;padding:3px 10px;border-radius:4px;font-family:'Inter',sans-serif;font-size:12px;font-weight:500;color:#52525b;background:#f4f4f5;border:1px solid #e4e4e7;white-space:nowrap;line-height:1.4;}
    .pet-cat-tag.owned{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}
    .pet-cat-tag.adopted{background:#eff6ff;color:#1e40af;border-color:#bfdbfe;}
    .pet-adopted-date{font-family:'Inter',sans-serif;font-size:12px;color:#71717a;white-space:nowrap;}

    #my-pets .client-name{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:600;line-height:1.4;color:#18181b;}
    #my-pets .pet-id-label{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;line-height:1.4;color:#18181b;}
    #adoption .pet-id-label{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;line-height:1.4;color:#18181b;}
    #my-pets .contact-main{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;line-height:1.4;color:#18181b;}
    #my-reports .contact-main{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;line-height:1.4;color:#18181b;}
    #my-reports #reportTable .client-name,
    #my-reports #reportTable .report-type-value,
    #my-reports #reportTable .report-location-value{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;}
    #appointments .contact-main{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:400;line-height:1.4;color:#18181b;}

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
    .appt-dropdown-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:6px;color:#18181b;font-family:'Inter',sans-serif;font-size:13px;font-weight:500;text-decoration:none;cursor:pointer;white-space:nowrap;}
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
    .sd-label { font-family:'Inter',sans-serif; font-size:13px; font-weight:500; line-height:1; color:#18181b; }
    .sd-label .req { color:#dc2626; }
    .sd-input { width:100%; height:36px; padding:0 12px; font-family:'Inter',sans-serif; font-size:14px; color:#18181b; background:#fff; border:1px solid #e4e4e7; border-radius:6px; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); outline:none; transition:box-shadow 0.15s ease, border-color 0.15s ease; box-sizing:border-box; }
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

    /* ï¿½"?ï¿½"? Pet Profile Drawer (shadcn right drawer) ï¿½"?ï¿½"? */
    .pet-drawer-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:998; display:none; }
    .pet-drawer-overlay.open { display:block; }
    .pet-drawer { position:fixed; top:0; right:0; bottom:0; width:min(480px,92vw); background:#fff; z-index:999; box-shadow:-8px 0 40px rgba(15,23,32,.18); transform:translateX(105%); transition:transform 0.32s cubic-bezier(.32,.72,.25,1); display:flex; flex-direction:column; overflow:hidden; }
    .pet-drawer.open { transform:translateX(0); }
    .pet-drawer-head { display:flex; align-items:flex-start; gap:14px; padding:22px 24px; border-bottom:1px solid #e4e4e7; }
    .pet-drawer-avatar { width:72px; height:72px; border-radius:50%; overflow:hidden; background:#e4e4e7; color:#71717a; display:flex; align-items:center; justify-content:center; font-size:26px; font-weight:600; flex-shrink:0; }
    .pet-drawer-avatar img { width:100%; height:100%; object-fit:cover; }
    .pet-drawer-name { font-family:'Inter',sans-serif; font-size:18px; font-weight:600; color:#18181b; letter-spacing:-0.025em; margin:0; }
    .pet-drawer-ref { font-family:'Inter',sans-serif; font-size:14px; font-weight:400; color:#71717a; margin:2px 0 0; }
    .pet-drawer-sub { font-family:'Inter',sans-serif; font-size:14px; font-weight:400; color:#71717a; margin:6px 0 0; }
    .pet-drawer-close { margin-left:auto; width:32px; height:32px; border-radius:6px; background:transparent; border:none; color:#71717a; font-size:16px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:background .15s,color .15s; }
    .pet-drawer-close:hover { background:#f4f4f5; color:#18181b; }
    .pet-drawer-body { flex:1; overflow-y:auto; padding:24px; }
    .pet-drawer-tabs { display:flex; gap:4px; background:#f4f4f5; padding:4px; border-radius:8px; margin-bottom:22px; }
    .pet-drawer-tab { flex:1; padding:8px 12px; border:none; border-radius:6px; background:transparent; color:#71717a; font-family:'Inter',sans-serif; font-size:14px; font-weight:500; cursor:pointer; transition:background .15s,color .15s; }
    .pet-drawer-tab.active { background:#fff; color:#18181b; box-shadow:0 1px 2px rgba(0,0,0,.06); }
    .pet-drawer-section-title { font-family:'Inter',sans-serif; font-size:12px; font-weight:600; color:#71717a; text-transform:uppercase; letter-spacing:.04em; margin:0 0 12px; }
    .pet-drawer-info { display:grid; grid-template-columns:1fr 1fr; gap:16px 24px; margin-bottom:22px; }
    .pet-drawer-info-item-full { grid-column:1 / -1; }
    .pet-drawer-info-item .k { font-family:'Inter',sans-serif; font-size:14px; font-weight:500; color:#71717a; }
    .pet-drawer-info-item .v { font-family:'Inter',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin-top:2px; word-break:break-word; }
    .pet-vax-box { background:#fafafa; border:1px solid #e4e4e7; border-radius:8px; padding:16px 20px; }
    .pet-vax-box .vax-head { font-family:'Inter',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin-bottom:4px; }
    .pet-vax-box .vax-desc { font-family:'Inter',sans-serif; font-size:14px; font-weight:400; color:#71717a; line-height:1.4; }
    .pet-vax-box .sd-btn { margin-top:12px; }
    .pet-drawer-foot { display:flex; gap:10px; padding:16px 24px; border-top:1px solid #e4e4e7; }
    .pet-drawer-foot .sd-btn { flex:1; justify-content:center; }
    @media (max-width:768px) {
      .appt-header-right { width:100%; }
      .appt-search-wrap { flex:1 1 100%; min-width:0; }
      .appt-search-input { width:100%; min-width:0; }
      #my-pets .appt-card-header > .sd-btn { width:100%; margin-left:0 !important; }
      #my-pets .appt-status-select { width:100%; }

      #my-pets {
        width:100%;
        max-width:100%;
        min-width:0;
      }

      #my-pets .pets-kpi-grid {
        grid-template-columns:repeat(2, minmax(0, 1fr)) !important;
        gap:10px !important;
      }

      #my-pets .pets-kpi-card {
        min-width:0;
        padding:16px 10px !important;
        overflow-wrap:anywhere;
      }

      #my-pets .pets-kpi-card p {
        max-width:100%;
        overflow-wrap:anywhere;
      }

      #my-pets .appt-db-table-area {
        width:100%;
        max-width:100%;
        min-width:0;
        overflow-x:auto;
        overflow-y:hidden;
        -webkit-overflow-scrolling:touch;
      }

      #my-pets .appt-table {
        min-width:820px;
      }

      #my-pets .appt-pagination {
        width:100%;
        padding:12px;
      }

      #my-pets .appt-pagination .dt-pagination-right {
        width:100%;
        flex-direction:column;
        align-items:stretch;
        gap:10px;
      }

      #my-pets .appt-pagination .dt-rows,
      #my-pets .appt-pagination .dt-page-label,
      #my-pets .appt-pagination .dt-page-btns {
        width:100%;
        justify-content:space-between;
      }

      #my-pets .appt-pagination .dt-page-label {
        text-align:left;
      }

      #addPetModal.modal,
      #editPetModal.modal {
        align-items:flex-start;
        overflow-y:auto;
        padding:8px;
      }

      #addPetModal .sd-dialog,
      #editPetModal .sd-dialog,
      #addPetModal .sd-dialog-content,
      #editPetModal .sd-dialog-content {
        width:100%;
        max-width:100%;
        min-width:0;
      }

      #addPetModal .sd-dialog-content,
      #editPetModal .sd-dialog-content {
        max-height:calc(100dvh - 16px);
        padding:18px 16px;
        overflow-y:auto;
      }

      #addPetModal .sd-dialog-title,
      #editPetModal .sd-dialog-title {
        padding-right:32px;
        line-height:1.25;
      }

      #addPetModal .sd-form-grid,
      #editPetModal .sd-form-grid {
        grid-template-columns:1fr;
        gap:12px;
      }

      #addPetModal .sd-dialog-footer,
      #editPetModal .sd-dialog-footer {
        flex-direction:column;
        align-items:stretch;
      }

      #addPetModal .sd-dialog-footer .sd-btn,
      #editPetModal .sd-dialog-footer .sd-btn {
        width:100%;
      }

      #addPetModal .sd-photo-upload,
      #editPetModal .sd-photo-upload {
        width:100%;
        max-width:100%;
      }

      #editPetModal .sd-photo-row {
        flex-direction:column;
      }

      #editPetModal .sd-photo-current,
      #editPetModal .sd-photo-upload {
        width:100%;
        max-width:100%;
      }
    }

    /* Resident data tables follow the All Clients table shell and pagination. */
    #appointments .appt-db-table-area,
    #my-reports .appt-db-table-area,
    #my-pets .appt-db-table-area,
    #adoption .appt-db-table-area {
      min-height:var(--staff-table-min-height);
      border:1px solid #e4e4e7;
      border-radius:8px;
      background:#fff;
    }

    #appointments .appt-pagination,
    #my-reports .appt-pagination,
    #my-pets .appt-pagination,
    #adoption .appt-pagination {
      min-height:57px;
      margin-top:0;
      border-top:1px solid #e4e4e7;
      background:#fff;
    }

    #appointments .resident-row-menu,
    #my-reports .resident-row-menu,
    #my-pets .resident-row-menu,
    #adoption .resident-row-menu {
      min-width:190px;
    }

    .resident-row-menu .row-menu-item.danger {
      color:#dc2626;
    }

    #appointments .resident-row-menu .row-menu-item.danger,
    #my-reports .resident-row-menu .row-menu-item.danger,
    #my-pets .resident-row-menu .row-menu-item.danger,
    #adoption .resident-row-menu .row-menu-item.danger {
      color:#dc2626;
    }

    @media (max-width:768px) {
      #appointments .appt-db-table-area,
      #my-reports .appt-db-table-area,
      #my-pets .appt-db-table-area,
      #adoption .appt-db-table-area {
        overflow-x:auto;
        -webkit-overflow-scrolling:touch;
      }

      #appointments .appt-table,
      #my-reports .appt-table,
      #my-pets .appt-table,
      #adoption .appt-table,
      #adoption .cv-table {
        min-width:760px;
      }

      #appointments .appt-pagination,
      #my-reports .appt-pagination,
      #my-pets .appt-pagination,
      #adoption .appt-pagination {
        align-items:stretch;
        flex-direction:column;
        gap:10px;
      }

      #appointments .dt-pagination-right,
      #my-reports .dt-pagination-right,
      #my-pets .dt-pagination-right,
      #adoption .dt-pagination-right {
        width:100%;
        align-items:stretch;
        flex-direction:column;
        gap:10px;
      }

      #appointments .dt-rows,
      #my-reports .dt-rows,
      #my-pets .dt-rows,
      #adoption .dt-rows,
      #appointments .dt-page-label,
      #my-reports .dt-page-label,
      #my-pets .dt-page-label,
      #adoption .dt-page-label,
      #appointments .dt-page-btns,
      #my-reports .dt-page-btns,
      #my-pets .dt-page-btns,
      #adoption .dt-page-btns {
        width:100%;
        justify-content:space-between;
      }
    }
  </style>

  <!-- ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"? MY REPORTS ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"? -->
  <section id="my-reports" class="content-section">
    <!-- Overview KPIs -->
    <div class="rpt-kpis">
      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;text-align:center;font-family:'Inter',sans-serif;">
        <p style="font-size:14px;font-weight:500;color:#18181b;margin:0 0 6px;text-transform:uppercase;letter-spacing:0.03em;">Total Reports</p>
        <p style="font-size:26px;font-weight:600;color:#18181b;letter-spacing:-0.02em;line-height:1;margin:0 0 6px;" id="rptTotal">0</p>
        <p style="font-size:14px;color:#71717a;margin:0;">All submitted reports</p>
      </div>
      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;text-align:center;font-family:'Inter',sans-serif;">
        <p style="font-size:14px;font-weight:500;color:#18181b;margin:0 0 6px;text-transform:uppercase;letter-spacing:0.03em;">Pending Review</p>
        <p style="font-size:26px;font-weight:600;color:#18181b;letter-spacing:-0.02em;line-height:1;margin:0 0 6px;" id="rptPendingReview">0</p>
        <p style="font-size:14px;color:#71717a;margin:0;">Awaiting validation</p>
      </div>
      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;text-align:center;font-family:'Inter',sans-serif;">
        <p style="font-size:14px;font-weight:500;color:#18181b;margin:0 0 6px;text-transform:uppercase;letter-spacing:0.03em;">In Progress</p>
        <p style="font-size:26px;font-weight:600;color:#18181b;letter-spacing:-0.02em;line-height:1;margin:0 0 6px;" id="rptInProgress">0</p>
        <p style="font-size:14px;color:#71717a;margin:0;">Under review / handled</p>
      </div>
      <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px;text-align:center;font-family:'Inter',sans-serif;">
        <p style="font-size:14px;font-weight:500;color:#18181b;margin:0 0 6px;text-transform:uppercase;letter-spacing:0.03em;">Resolved</p>
        <p style="font-size:26px;font-weight:600;color:#18181b;letter-spacing:-0.02em;line-height:1;margin:0 0 6px;" id="rptResolved">0</p>
        <p style="font-size:14px;color:#71717a;margin:0;">Completed cases</p>
      </div>
    </div>

    <?php
    $knownReportTypes = ['stray' => 'Stray Animal', 'rabies' => 'Rabies Case', 'injured' => 'Injured Animal', 'dead' => 'Dead Animal', 'other' => 'Other'];
    function rptStatusLabel($s) {
        switch ($s) {
        case 'pending': return 'Pending Barangay Review';
        case 'validated': return 'Pending DCVS Review';
        case 'confirmed':
        case 'scheduled': return 'In Progress';
            case 'resolved': return 'Resolved';
            case 'rejected': return 'Closed';
            default: return ucfirst(str_replace('_', ' ', $s));
        }
    }
    function rptStatusClass($s) {
        switch ($s) {
            case 'pending': return 'st-pending';
          case 'validated': return 'st-awaiting';
          case 'confirmed':
          case 'scheduled': return 'st-review';
            case 'resolved': return 'st-resolved';
            case 'rejected': return 'st-rejected';
            default: return 'st-pending';
        }
    }
    $rptStatusColors = [
      'pending'   => ['bg' => '#FEF3C7', 'color' => '#92400E', 'border' => '#FDE68A'],
      'validated' => ['bg' => '#dcebe0', 'color' => '#045e1f', 'border' => '#bbf7d0'],
      'confirmed' => ['bg' => '#EFF6FF', 'color' => '#1E40AF', 'border' => '#BFDBFE'],
      'scheduled' => ['bg' => '#EFF6FF', 'color' => '#1E40AF', 'border' => '#BFDBFE'],
      'resolved'  => ['bg' => '#0E8131', 'color' => '#ffffff', 'border' => '#0E8131'],
      'rejected'  => ['bg' => '#FEE2E2', 'color' => '#991B1B', 'border' => '#FECACA'],
    ];
    ?>
    <?php if (empty($reports)): ?>
      <div class="rpt-empty">
        <i class="fas fa-file-medical-alt"></i>
        <h3>No reports submitted yet</h3>
        <p>Submit your first report to help us keep the community safe.</p>
        <a href="submit-report-page.php" class="sd-btn sd-btn-default"><i class="fas fa-plus"></i> Submit a Report</a>
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
      <a href="submit-report-page.php" class="sd-btn sd-btn-default" style="margin-left:auto;">
        <i class="fas fa-plus"></i> Submit a Report
      </a>
    </div>

    <div class="appt-db-table-area dt-container">
      <table class="appt-table" id="reportTable">
        <thead>
          <tr class="sd-tr">
            <th class="sd-th"><button type="button" class="th-sort" data-col="ref" onclick="RPT.sortBy('ref')">Report ID<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="type" onclick="RPT.sortBy('type')">Type<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="location" onclick="RPT.sortBy('location')">Location<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="date" onclick="RPT.sortBy('date')">Incident Date<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="status" onclick="RPT.sortBy('status')">Status<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="category" onclick="RPT.sortBy('category')">Category<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th"><button type="button" class="th-sort" data-col="resolved" onclick="RPT.sortBy('resolved')">Date Resolved<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
            <th class="sd-th" style="width:40px;"></th>
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
            $incidentAt = !empty($report['incident_at']) ? $report['incident_at'] : $report['created_at'];
            $rDate = date('M d, Y', strtotime($incidentAt));
            $rResolvedDate = ($rStatus === 'resolved' && !empty($report['updated_at'])) ? date('M d, Y', strtotime($report['updated_at'])) : '-';
            $rResolvedRaw = ($rStatus === 'resolved' && !empty($report['updated_at'])) ? $report['updated_at'] : '';
          ?>
          <tr class="sd-tr" data-id="<?php echo (int)$report['report_id']; ?>" data-status="<?php echo $rStatus; ?>" data-statuscls="<?php echo $rStatusClass; ?>" data-type="<?php echo htmlspecialchars(strtolower($rTypeLabel)); ?>" data-location="<?php echo htmlspecialchars(strtolower($report['location_address'])); ?>" data-date="<?php echo (int)strtotime($incidentAt); ?>" data-resolved="<?php echo htmlspecialchars($rResolvedRaw); ?>" onclick="RPT.view(<?php echo (int)$report['report_id']; ?>)">
            <td class="sd-td"><div class="client-name"><?php echo htmlspecialchars($rRef); ?></div></td>
            <td class="sd-td"><div class="contact-main contact-normal report-type-value"><?php echo htmlspecialchars($rTypeLabel); ?> Report</div></td>
            <td class="sd-td"><div class="contact-main contact-normal report-location-value"><?php echo htmlspecialchars($report['location_address']); ?></div></td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo $rDate; ?></div></td>
            <td class="sd-td"><span class="appt-status-pill<?php echo $rStatus === 'resolved' ? ' report-status-resolved' : ''; ?>" style="background:<?php echo $rsc['bg']; ?>;color:<?php echo $rsc['color']; ?>;border-color:<?php echo $rsc['border']; ?>;"><?php echo $rStatusLabel; ?></span></td>
            <td class="sd-td"><div class="contact-main contact-normal report-type-value"><?php echo htmlspecialchars($rTypeLabel); ?></div></td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo $rResolvedDate; ?></div></td>
            <td class="sd-td sd-td-action" onclick="event.stopPropagation()">
              <button class="sd-row-actions" title="More options" onclick="event.stopPropagation();rptRowActions(event,<?php echo (int)$report['report_id']; ?>)"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div id="reportPagination" class="appt-pagination dt-pagination"></div>
    <?php endif; ?>
  </section>

  <!-- ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"? SUBMIT A REPORT ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"? -->
  <style>
    /* ï¿½"?ï¿½"? My Reports (My Pets design) ï¿½"?ï¿½"? */
    #my-reports { font-family:'Inter',sans-serif; }

    .rpt-empty { text-align:center; padding:3.5rem 1.5rem; color:#64748b; border:2px dashed #e2e8f0; border-radius:16px; }
    .rpt-empty i { font-size:3rem; color:#94a3b8; display:block; margin-bottom:1rem; }
    .rpt-empty h3 { font-size:1.05rem; font-weight:700; color:#0f172a; margin:0 0 4px; }
    .rpt-empty p { font-size:0.85rem; margin:0 0 1.25rem; }
    .rpt-empty .sd-btn { margin:0 auto; }

    /* ï¿½"?ï¿½"? Report Details Drawer (shadcn right drawer) ï¿½"?ï¿½"? */
    .rpt-drawer-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:998; display:none; }
    .rpt-drawer-overlay.open { display:block; }
    .rpt-drawer { position:fixed; top:0; right:0; bottom:0; width:min(480px,92vw); max-width:100vw; background:#fff; z-index:999; box-shadow:-8px 0 40px rgba(15,23,32,.18); transform:translateX(105%); transition:transform 0.32s cubic-bezier(.32,.72,.25,1); display:flex; flex-direction:column; overflow:hidden; }
    .rpt-drawer.open { transform:translateX(0); }
    .rpt-drawer-head { display:flex; align-items:flex-start; gap:14px; padding:24px; border-bottom:1px solid #e4e4e7; }
    #rptDrawer .rpt-drawer-head { border-bottom:none; padding-bottom:0; }
    #rptDrawer .rpt-drawer-body { padding-top:18px; }
    .rpt-drawer-title { font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; color:#18181b; letter-spacing:-0.025em; margin:0; }
    .rpt-drawer-ref { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#71717a; line-height:1.5; margin:0; }
    .rpt-drawer-head > div { display:flex; flex-direction:column; gap:6px; }
    #rptDrawer .rpt-tabs { display:flex; justify-content:center; gap:28px; border-bottom:1px solid #e6e9ee; margin-bottom:20px; }
    #rptDrawer .rpt-tab { padding:0 0 12px; font-size:14px; font-weight:600; color:#94a3b8; cursor:pointer; border:none; border-bottom:2px solid transparent; border-top:0; border-left:0; border-right:0; background:none; font-family:inherit; }
    #rptDrawer .rpt-tab:hover { color:#18181b; }
    #rptDrawer .rpt-tab.active { color:#045e1f; border-bottom-color:#046b25; }
    #rptDrawer .rpt-detail-body { display:none; }
    #rptDrawer .rpt-detail-body.active { display:block; }
    #rptDrawer .rpt-detail-body > .pet-drawer-section-title:first-child { margin-top:18px; }
    #rptDrawer .pet-drawer-section-title { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; text-transform:none; letter-spacing:0; margin:0 0 10px; }
    #rptDrawer .pet-drawer-info-item .k { font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:600; line-height:1.4; color:#71717a; margin:0; }
    #rptDrawer .rpt-photo-none { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#71717a; margin:0; }
    .rpt-drawer-close { margin-left:auto; width:24px; height:24px; border-radius:4px; background:transparent; border:none; color:#71717a; font-size:16px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:background .15s,color .15s; flex-shrink:0; }
    .rpt-drawer-close:hover { background:#f4f4f5; color:#18181b; }
    .rpt-drawer-body { flex:1; overflow-y:auto; padding:24px; }
    .rpt-drawer-section-title { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; text-transform:none; letter-spacing:0; line-height:1.4; margin:0 0 12px; }
    .rpt-drawer-section-title:first-child { margin-top:0; }
    .rpt-detail-label { font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:600; color:#71717a; margin-bottom:2px; }
    .rpt-detail-value { font-family:'Inter','Segoe UI',sans-serif; font-size:15px; font-weight:400; color:#18181b; line-height:1.45; word-break:break-word; }
    .rpt-detail-block { margin-bottom:1rem; }
    .rpt-info-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px 24px; }
    @media (max-width:560px){ .rpt-info-grid { grid-template-columns:1fr; } }
    .rpt-photos { display:flex; gap:12px; flex-wrap:wrap; }
    .rpt-photo-thumb { width:100%; height:220px; border-radius:8px; overflow:hidden; cursor:pointer; background:#f4f4f5; display:flex; align-items:center; justify-content:center; color:#71717a; border:1px solid #e4e4e7; }
    .rpt-photo-thumb img { width:100%; height:100%; object-fit:cover; }
    .rpt-photo-thumb video { width:100%; height:100%; object-fit:contain; background:#000; }
    .rpt-video-thumb { background:#000; }
    .rpt-status-line { display:flex; align-items:center; gap:8px; font-family:'Inter',sans-serif; font-size:14px; font-weight:500; color:#18181b; }
    .rpt-status-line i { color:#057b2d; }
    /* appointment drawer sections */
    #apptDrawer .rpt-drawer-head { border-bottom:none; padding-bottom:0; }
    #apptDrawer .rpt-drawer-body { padding-top:18px; }
    #apptDrawer .pet-drawer-section-title { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; text-transform:none; letter-spacing:0; margin:0 0 10px; }
    .rpt-divider { border:0; border-top:1px solid #e4e4e7; margin:20px 0; }
    #apptDrawer .rpt-drawer-foot { justify-content:center; }
    #apptDrawer .rpt-drawer-foot .sd-btn { flex:0 0 auto; }
    /* drawer status badge + timeline */
    .rpt-status-badge { display:inline-flex; align-items:center; gap:6px; font-family:'Inter',sans-serif; font-size:12px; font-weight:600; padding:3px 10px; border:1px solid transparent; border-radius:20px; white-space:nowrap; }
    .rpt-status-badge .st-dot { width:7px; height:7px; border-radius:50%; }
    .rpt-status-badge.st-pending { background:#FEF3C7; color:#92400E; border-color:#FDE68A; } .rpt-status-badge.st-pending .st-dot { background:#92400E; }
    .rpt-status-badge.st-awaiting { background:#dcebe0; color:#045e1f; border-color:#bbf7d0; } .rpt-status-badge.st-awaiting .st-dot { background:#045e1f; }
    .rpt-status-badge.st-review { background:#EFF6FF; color:#1E40AF; border-color:#BFDBFE; } .rpt-status-badge.st-review .st-dot { background:#1E40AF; }
    .rpt-status-badge.st-resolved { background:#0E8131; color:#ffffff; border-color:#0E8131; } .rpt-status-badge.st-resolved .st-dot { background:#ffffff; }
    .rpt-status-badge.st-rejected { background:#FEE2E2; color:#991B1B; border-color:#FECACA; } .rpt-status-badge.st-rejected .st-dot { background:#991B1B; }
    #my-reports #reportTable .appt-status-pill.report-status-resolved { background:#0E8131; color:#ffffff; border-color:#0E8131; }
    .rpt-timeline { display:flex; align-items:center; gap:8px; }
    .tl-step { display:inline-flex; align-items:center; gap:5px; font-family:'Inter',sans-serif; font-size:14px; font-weight:500; color:#a1a1aa; }
    .tl-step i { font-size:12px; }
    .tl-step.done { color:#057b2d; }
    .tl-step.current { color:#2563eb; }
    .tl-sep { flex:0 0 28px; height:2px; background:#e4e4e7; border-radius:2px; }
    .rpt-timeline-rejected .tl-step.done { color:#991b1b; }
    .rpt-next { background:#fafafa; border:1px solid #e4e4e7; border-radius:8px; padding:16px 20px; }
    .rpt-next-title { font-family:'Inter',sans-serif; font-size:14px; font-weight:600; color:#18181b; margin-bottom:8px; }
    .rpt-next ol { margin:0; padding-left:1.15rem; }
    .rpt-next li { font-family:'Inter',sans-serif; font-size:14px; font-weight:400; color:#71717a; line-height:1.5; }
    .rpt-drawer-foot { display:flex; gap:8px; padding:16px 24px; border-top:1px solid #e4e4e7; align-items:center; }
    .rpt-drawer-foot .sd-btn { flex:1; justify-content:center; }
    .rpt-more-wrap { position:relative; }
    .rpt-more-btn { width:32px; height:32px; border-radius:6px; border:none; background:transparent; color:#71717a; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; }
    .rpt-more-btn:hover { background:#f4f4f5; color:#18181b; }
    .rpt-more-pop { position:absolute; bottom:calc(100% + 6px); right:0; background:#fff; border:1px solid #e4e4e7; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.12); padding:6px; min-width:180px; display:none; z-index:30; }
    .rpt-more-pop.open { display:block; }
    .rpt-more-item { display:flex; align-items:center; gap:10px; width:100%; padding:9px 10px; border:none; background:none; border-radius:6px; font-family:'Inter',sans-serif; font-size:13px; font-weight:500; color:#18181b; cursor:pointer; text-align:left; }
    .rpt-more-item:hover { background:#f4f4f5; color:#18181b; }
    .rpt-more-item.danger { color:#dc2626; } .rpt-more-item.danger:hover { background:#fef2f2; }
    .rpt-more-item i { width:16px; text-align:center; }

    #apptDrawer .pet-drawer-info { gap:16px 20px; margin-bottom:24px; }
    #apptDrawer .pet-drawer-info-item .k { font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:600; line-height:1.4; color:#71717a; margin:0; }
    #apptDrawer .pet-drawer-info-item .v { font-family:'Inter','Segoe UI',sans-serif; font-size:15px; font-weight:400; line-height:1.4; color:#18181b; margin-top:2px; word-break:break-word; }
    #rptDrawer .pet-drawer-info-item .v { font-family:'Inter','Segoe UI',sans-serif; font-size:15px; font-weight:400; line-height:1.4; color:#18181b; margin-top:2px; word-break:break-word; }
    #apptDrawer .pet-drawer-info-item-full { grid-column:1 / -1; }
    #apptDrawer .appt-notes { background:#f8f9fa; border:1px solid #e4e4e7; border-radius:8px; padding:12px 14px; }
    #apptDrawer .rpt-next { background:#fafafa; border:1px solid #e4e4e7; border-radius:8px; padding:16px 20px; }
    #apptDrawer .rpt-next-title { font-size:14px; font-weight:600; color:#18181b; line-height:1.4; margin-bottom:8px; }
    #apptDrawer .rpt-next li { font-size:14px; font-weight:400; color:#71717a; line-height:1.5; }
    @media (max-width:560px){
      .rpt-drawer-head, .rpt-drawer-body { padding-left:20px; padding-right:20px; }
      .rpt-drawer-foot { padding-left:20px; padding-right:20px; }
      #apptDrawer .pet-drawer-info { grid-template-columns:1fr; }
      #apptDrawer .rpt-drawer-foot { flex-wrap:wrap; }
      #apptDrawer .rpt-drawer-foot .sd-btn { flex:1 1 100%; }
    }

    /* ï¿½"?ï¿½"? Submit Report wizard ï¿½"?ï¿½"? */
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

    /* Submit Report removed — now uses submit-report-page.php */
    @media (max-width:560px){ .rpt-type-grid { grid-template-columns:1fr; } }
    @media (max-width:768px) {
      .appt-header-right { width:100%; }
      .appt-search-input { width:100%; }
    }

    /* ï¿½"?ï¿½"? Appointments (My Reports design) ï¿½"?ï¿½"? */
    #appointments { font-family:'Inter',sans-serif; }
    #appointments .appt-kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px; }
    #appointments .appt-kpi-card{ display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:20px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:none; font-family:'Inter',sans-serif; }
    #appointments .appt-kpi-label{ font-family:'Inter',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin:0 0 6px; text-transform:uppercase; letter-spacing:0.03em; }
    #appointments .appt-kpi-value{ font-family:'Inter',sans-serif; font-size:26px; font-weight:600; color:#18181b; letter-spacing:-0.02em; line-height:1; margin:0 0 6px; }
    #appointments .appt-kpi-sub{ font-family:'Inter',sans-serif; font-size:14px; color:#71717a; }
    @media (max-width:1180px){ #appointments .appt-kpis{ grid-template-columns:repeat(2,1fr); } }
    @media (max-width:768px){ #appointments .appt-kpis{ grid-template-columns:1fr 1fr; } }
    @media (max-width:480px){ #appointments .appt-kpis{ grid-template-columns:1fr; } }

    /* My Waitlist follows the staff waitlist shadcn table layout. */
    #my-waitlist { font-family:'Inter','Segoe UI',sans-serif; color:#18181b; }
    #my-waitlist .resident-waitlist-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; margin-bottom:20px; }
    #my-waitlist .resident-waitlist-kpi { display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:84px; padding:14px 16px; background:#fff; border:1px solid #e5e7eb; border-radius:14px; box-shadow:0 1px 3px rgba(16,24,40,.05),0 1px 2px rgba(16,24,40,.03); text-align:center; }
    #my-waitlist .resident-waitlist-kpi-label { margin:0 0 6px; color:#18181b; font-size:14px; font-weight:500; text-transform:uppercase; }
    #my-waitlist .resident-waitlist-kpi-value { margin:0 0 6px; color:#18181b; font-size:26px; font-weight:600; letter-spacing:-.02em; line-height:1; }
    #my-waitlist .resident-waitlist-kpi-sub { margin:0; color:#71717a; font-size:14px; }
    #my-waitlist .resident-waitlist-toolbar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:14px; }
    #my-waitlist .resident-waitlist-toolbar input,
    #my-waitlist .resident-waitlist-toolbar select { height:36px; box-sizing:border-box; padding:0 10px; border:1px solid #e4e4e7; border-radius:6px; background:#fff; color:#18181b; font:14px 'Inter','Segoe UI',sans-serif; box-shadow:0 1px 2px rgba(0,0,0,.05); }
    #my-waitlist .resident-waitlist-toolbar input[type="search"] { flex:0 1 280px; width:280px; min-width:180px; }
    #my-waitlist .resident-waitlist-toolbar select { min-width:150px; padding:0 26px 0 10px; appearance:none; -webkit-appearance:none; background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 10px center; font-size:14px; font-weight:500; font-family:inherit; cursor:pointer; }
    #my-waitlist .resident-waitlist-toolbar input:focus,
    #my-waitlist .resident-waitlist-toolbar select:focus { outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #my-waitlist .resident-waitlist-date-filter { position:relative; display:inline-flex; }
    #my-waitlist .resident-waitlist-date-filter input { position:absolute; width:1px; height:1px; opacity:0; pointer-events:none; }
    #my-waitlist .resident-waitlist-toolbar button { height:36px; padding:0 12px; border:1px solid #e4e4e7; border-radius:6px; background:#fff; color:#18181b; font:500 14px 'Inter','Segoe UI',sans-serif; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 1px 2px rgba(0,0,0,.05); }
    #my-waitlist .resident-waitlist-toolbar button[hidden] { display:none!important; }
    #my-waitlist .resident-waitlist-date-button { width:36px; min-width:36px; padding:0!important; justify-content:center!important; }
    #my-waitlist .resident-waitlist-date-button.has-date { border-color:#18181b; }
    #my-waitlist .resident-waitlist-date-clear { width:30px; min-width:30px; padding:0!important; margin-left:-7px; }
    #my-waitlist .resident-waitlist-card { overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#fff; }
    #my-waitlist .resident-waitlist-table-wrap { min-height:560px; overflow-x:auto; }
    #my-waitlist .resident-waitlist-table { width:100%; min-width:900px; border-collapse:collapse; table-layout:auto; }
    #my-waitlist .resident-waitlist-table th { height:42px; padding:0 12px; border-bottom:1px solid #e4e4e7; background:#fafafa; color:#71717a; font-size:14px; font-weight:500; text-align:left; white-space:nowrap; }
    #my-waitlist .resident-waitlist-table td { padding:12px; border-bottom:1px solid #e4e4e7; color:#18181b; font-size:14px; vertical-align:middle; }
    #my-waitlist .resident-waitlist-table tr:last-child td { border-bottom:0; }
    #my-waitlist .resident-waitlist-pet-cell { display:flex; align-items:center; gap:10px; min-width:0; }
    #my-waitlist .resident-waitlist-pet-avatar { width:36px; height:36px; display:inline-flex; align-items:center; justify-content:center; flex:0 0 36px; overflow:hidden; border-radius:50%; background:#e4e4e7; color:#71717a; font-size:13px; }
    #my-waitlist .resident-waitlist-pet-avatar img { width:100%; height:100%; object-fit:cover; }
    #my-waitlist .resident-waitlist-pet-name { min-width:0; overflow-wrap:anywhere; }
    #my-waitlist .resident-waitlist-subtext { margin-top:3px; color:#71717a; font-size:12px; }
    #my-waitlist .resident-waitlist-pill { display:inline-flex; align-items:center; padding:4px 10px; border:1px solid transparent; border-radius:4px; font-size:12px; font-weight:400; line-height:1.2; background:#f4f4f5; color:#52525b; }
    #my-waitlist .resident-waitlist-pill.waiting { background:#fff4cc; border-color:#ffda66; color:#a84b00; }
    #my-waitlist .resident-waitlist-pill.notified { background:#dbeafe; color:#1e40af; }
    #my-waitlist .resident-waitlist-pill.booked { background:#dcfce7; color:#166534; }
    #my-waitlist .resident-waitlist-action { display:inline-flex; align-items:center; justify-content:center; height:34px; padding:0 12px; border:1px solid #e4e4e7; border-radius:6px; background:#fff; color:#18181b; font:500 13px 'Inter','Segoe UI',sans-serif; text-decoration:none; cursor:pointer; white-space:nowrap; }
    #my-waitlist .resident-waitlist-action:hover { background:#f4f4f5; }
    #my-waitlist .resident-waitlist-action.primary { border-color:#18181b; background:#18181b; color:#fff; }
    #my-waitlist .resident-waitlist-action.primary:hover { background:#27272a; }
    #my-waitlist .resident-waitlist-action:disabled { opacity:.55; cursor:not-allowed; }
    #my-waitlist .resident-waitlist-row-actions { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border:0; border-radius:6px; background:transparent; color:#71717a; cursor:pointer; }
    #my-waitlist .resident-waitlist-row-actions:hover,
    #my-waitlist .resident-waitlist-row-actions[aria-expanded="true"] { background:#f4f4f5; color:#18181b; }
    #my-waitlist .resident-waitlist-row-actions svg { width:16px; height:16px; }
    #residentWaitlistRowMenu { position:fixed; z-index:1200; display:none; min-width:190px; padding:6px; border:1px solid #e6e9ee; border-radius:10px; background:#fff; box-shadow:0 4px 14px rgba(15,23,32,.06); font-family:'Inter','Segoe UI',sans-serif; }
    #residentWaitlistRowMenu.open { display:block; }
    #residentWaitlistRowMenu .resident-waitlist-menu-item { display:flex; align-items:center; gap:10px; width:100%; padding:9px 10px; border:0; border-radius:8px; background:none; color:#334155; font:500 13px/1.4 'Inter','Segoe UI',sans-serif; text-align:left; text-decoration:none; cursor:pointer; box-sizing:border-box; }
    #residentWaitlistRowMenu .resident-waitlist-menu-item:hover { background:#f8fafc; }
    #residentWaitlistRowMenu .resident-waitlist-menu-item.danger { color:#dc2626; }
    #residentWaitlistRowMenu .resident-waitlist-menu-item.danger:hover { background:#fef2f2; }
    #residentWaitlistRowMenu .resident-waitlist-menu-item svg { flex-shrink:0; width:15px; height:15px; }
    #my-waitlist .resident-waitlist-empty { display:none; padding:42px 16px!important; color:#71717a!important; text-align:center; }
    #my-waitlist .resident-waitlist-empty.visible { display:table-cell; }
    #my-waitlist .resident-waitlist-pagination { display:flex; align-items:center; justify-content:flex-end; gap:12px; flex-wrap:wrap; padding:12px 8px; margin-top:8px; font-family:'Inter','Segoe UI',sans-serif; }
    #my-waitlist .resident-waitlist-pagination-right { display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
    #my-waitlist .resident-waitlist-rows { display:flex; align-items:center; gap:8px; color:#71717a; font-size:14px; }
    #my-waitlist .resident-waitlist-rows select { height:32px; width:70px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; padding:0 8px; font:14px 'Inter','Segoe UI',sans-serif; color:#18181b; }
    #my-waitlist .resident-waitlist-page-label { width:110px; text-align:center; font-size:14px; font-weight:500; color:#18181b; white-space:nowrap; }
    #my-waitlist .resident-waitlist-page-btns { display:flex; align-items:center; gap:4px; }
    #my-waitlist .resident-waitlist-page-btn { height:32px; width:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; cursor:pointer; }
    #my-waitlist .resident-waitlist-page-btn:hover:not(:disabled) { background:#f4f4f5; }
    #my-waitlist .resident-waitlist-page-btn:disabled { opacity:.5; cursor:not-allowed; }
    #residentWaitlistConfirm.modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,.5); padding:1rem; z-index:1100; }
    #residentWaitlistConfirm.modal.open { display:flex; }
    #residentWaitlistConfirm .sd-dialog-content { width:100%; max-width:420px; padding:24px; border:1px solid #e4e4e7; border-radius:8px; background:#fff; box-shadow:0 20px 25px rgba(15,23,32,.18); }
    #residentWaitlistConfirm .sd-dialog-header { text-align:left; }
    #residentWaitlistConfirm .sd-dialog-title { font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; color:#18181b; letter-spacing:-.025em; }
    #residentWaitlistConfirm .sd-dialog-description { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; line-height:1.5; }
    #residentWaitlistConfirm .sd-dialog-footer { display:flex; justify-content:flex-end; gap:8px; }
    @media(max-width:1180px) { #my-waitlist .resident-waitlist-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    @media(max-width:800px) { #my-waitlist .resident-waitlist-kpis { grid-template-columns:1fr 1fr; gap:10px; } #my-waitlist .resident-waitlist-pagination-right { justify-content:center; gap:12px; } }
    @media(max-width:480px) { #my-waitlist .resident-waitlist-kpis { grid-template-columns:1fr; } }

    /* Request Appointment dialog (shadcn) */
    /* Edit Appointment dialog (shadcn) */
    #editAppointmentModal.modal { background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
    #editAppointmentModal .sd-dialog { width:100%; display:flex; justify-content:center; }
    #editAppointmentModal .sd-dialog-content { position:relative; width:100%; max-width:560px; background:#ffffff; border:1px solid #e4e4e7; border-radius:8px; padding:24px; display:flex; flex-direction:column; gap:16px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1); animation:modalIn 0.2s ease; max-height:90vh; overflow-y:auto; }
    #editAppointmentModal .sd-dialog-close { position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all 0.15s ease; }
    #editAppointmentModal .sd-dialog-close:hover { background:#f4f4f5; color:#18181b; }
    #editAppointmentModal .sd-dialog-header { display:flex; flex-direction:column; gap:6px; text-align:left; }
    #editAppointmentModal .sd-dialog-title { margin:0; font-family:'Inter',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
    #editAppointmentModal .sd-dialog-description { margin:0; font-size:14px; color:#71717a; line-height:1.5; }
    #editAppointmentModal .sd-dialog-footer { display:flex; justify-content:flex-end; gap:8px; }
    #editAppointmentModal .sd-field-group { display:flex; flex-direction:column; gap:12px; }
    #editAppointmentModal .sd-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px 16px; }
    #editAppointmentModal .sd-field { display:flex; flex-direction:column; gap:5px; }
    #editAppointmentModal .sd-input { width:100%; height:36px; padding:0 12px; font-family:'Inter',sans-serif; font-size:14px; color:#18181b; background:#fff; border:1px solid #e4e4e7; border-radius:6px; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); outline:none; transition:border-color 0.15s, box-shadow 0.15s; box-sizing:border-box; }
    #editAppointmentModal select.sd-input { appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; padding-right:36px; }
    #editAppointmentModal textarea.sd-input { height:auto; min-height:90px; padding:8px 12px; resize:vertical; line-height:1.5; }
    #editAppointmentModal .sd-input:focus { outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    #editAppointmentModal .sd-label { font-family:'Inter',sans-serif; font-size:14px; font-weight:500; line-height:1.25; color:#18181b; margin:0; display:block; }
    #editAppointmentModal .sd-label .req { color:#dc2626; }
    @media (max-width:560px){ #editAppointmentModal .sd-form-grid { grid-template-columns:1fr; } }
  </style>

<!-- ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"? APPOINTMENTS ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"? -->
  <section id="calendar-booking" class="content-section">
    <?php include __DIR__ . '/../calendar-booking.php'; ?>
  </section>
  <section id="my-waitlist" class="content-section">
    <section class="resident-waitlist-kpis" aria-label="My waitlist summary">
      <article class="resident-waitlist-kpi"><p class="resident-waitlist-kpi-label">Total Entries</p><p class="resident-waitlist-kpi-value"><?php echo $residentWaitlistStats['total']; ?></p><p class="resident-waitlist-kpi-sub">All your waitlist requests</p></article>
      <article class="resident-waitlist-kpi"><p class="resident-waitlist-kpi-label">Pending</p><p class="resident-waitlist-kpi-value"><?php echo $residentWaitlistStats['waiting']; ?></p><p class="resident-waitlist-kpi-sub">Waiting for an open slot</p></article>
      <article class="resident-waitlist-kpi"><p class="resident-waitlist-kpi-label">Notified</p><p class="resident-waitlist-kpi-value"><?php echo $residentWaitlistStats['notified']; ?></p><p class="resident-waitlist-kpi-sub">Slot available to claim</p></article>
      <article class="resident-waitlist-kpi"><p class="resident-waitlist-kpi-label">Most Requested Date</p><p class="resident-waitlist-kpi-value" style="font-size:20px;"><?php echo $residentWaitlistDemandDate ? date('M d, Y', strtotime($residentWaitlistDemandDate)) : '--'; ?></p><p class="resident-waitlist-kpi-sub"><?php echo $residentWaitlistDemandCount; ?> of your waiting requests</p></article>
    </section>
    <form class="resident-waitlist-toolbar" id="residentWaitlistToolbar" onsubmit="return false;">
      <input type="search" id="residentWaitlistSearch" placeholder="Search by pet or service" aria-label="Search waitlist">
      <span class="resident-waitlist-date-filter">
        <button type="button" class="resident-waitlist-date-button" id="residentWaitlistDateButton" aria-label="Filter by date" title="Filter by date"><i class="fas fa-filter" aria-hidden="true"></i></button>
        <input type="date" id="residentWaitlistDate" aria-label="Filter by preferred date">
      </span>
      <button type="button" class="resident-waitlist-date-clear" id="residentWaitlistClearDate" aria-label="Clear date filter" hidden><i class="fas fa-times"></i></button>
      <select id="residentWaitlistService" aria-label="Filter by service">
        <option value="">All services</option>
        <?php foreach (array_unique(array_column($residentWaitlist, 'appointment_type')) as $service): ?>
          <option value="<?php echo htmlspecialchars($service); ?>"><?php echo htmlspecialchars(ucfirst($service)); ?></option>
        <?php endforeach; ?>
      </select>
      <select id="residentWaitlistStatus" aria-label="Filter by status">
        <option value="">All statuses</option>
        <option value="waiting">Waiting</option>
        <option value="notified">Notified</option>
        <option value="booked">Booked</option>
        <option value="expired">Expired</option>
        <option value="cancelled">Cancelled</option>
      </select>
    </form>
    <section class="resident-waitlist-card">
      <div class="resident-waitlist-table-wrap">
        <table class="resident-waitlist-table">
          <thead><tr><th>Pet Name</th><th>Service</th><th>Preferred date</th><th>Time</th><th>Urgency</th><th>Date joined</th><th>Status</th><th></th></tr></thead>
          <tbody id="residentWaitlistRows">
          <?php foreach ($residentWaitlist as $entry):
            $petName = $entry['pet_name'] ?: '-';
            $dateLabel = date('M d, Y', strtotime($entry['preferred_date']));
            $timeLabel = $entry['preferred_time'] ? date('g:i A', strtotime($entry['preferred_time'])) : '';
          ?>
            <tr data-service="<?php echo htmlspecialchars(strtolower($entry['appointment_type'])); ?>"
                data-pet="<?php echo htmlspecialchars(strtolower($petName)); ?>"
                data-date="<?php echo htmlspecialchars($entry['preferred_date']); ?>"
                data-status="<?php echo htmlspecialchars($entry['status']); ?>"
                data-waitlist-id="<?php echo (int)$entry['waitlist_id']; ?>">
              <td><div class="resident-waitlist-pet-cell"><span class="resident-waitlist-pet-avatar"><?php if (!empty($entry['pet_photo'])): ?><img src="<?php echo htmlspecialchars($entry['pet_photo']); ?>" alt=""><?php else: ?><i class="fas fa-paw" aria-hidden="true"></i><?php endif; ?></span><span class="resident-waitlist-pet-name"><?php echo htmlspecialchars($petName); ?></span></div></td>
              <td><?php echo htmlspecialchars(ucfirst($entry['appointment_type'])); ?></td>
              <td><?php echo htmlspecialchars($dateLabel); ?></td>
              <td><?php echo $timeLabel ? htmlspecialchars($timeLabel) : '—'; ?></td>
              <td><?php echo htmlspecialchars(ucfirst($entry['urgency_level'])); ?></td>
              <td><?php echo date('M d, Y', strtotime($entry['created_at'])); ?></td>
              <td><span class="resident-waitlist-pill <?php echo htmlspecialchars($entry['status']); ?>"><?php echo htmlspecialchars(ucfirst($entry['status'])); ?></span><?php if ($entry['status'] === 'notified' && $entry['claim_expires_at']): ?><div class="resident-waitlist-subtext">Claim by <?php echo date('M d, Y g:i A', strtotime($entry['claim_expires_at'])); ?></div><?php endif; ?></td>
              <td style="text-align:right;white-space:nowrap;">
                <button type="button" class="resident-waitlist-row-actions" title="More options" aria-label="More options" aria-haspopup="menu" aria-expanded="false"
                  data-waitlist-id="<?php echo (int)$entry['waitlist_id']; ?>"
                  data-waitlist-status="<?php echo htmlspecialchars($entry['status']); ?>"
                  onclick="toggleResidentWaitlistMenu(event, <?php echo (int)$entry['waitlist_id']; ?>)">
                  <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
            <tr id="residentWaitlistEmpty" style="<?php echo $residentWaitlist ? 'display:none;' : ''; ?>"><td class="resident-waitlist-empty <?php echo $residentWaitlist ? '' : 'visible'; ?>" colspan="8"><?php echo $residentWaitlist ? 'No waitlist entries match these filters.' : 'You have no waitlist entries.'; ?></td></tr>
          </tbody>
        </table>
      </div>
    </section>
    <div class="resident-waitlist-pagination">
      <div class="resident-waitlist-pagination-right">
        <div class="resident-waitlist-rows"><span>Rows per page</span><select id="residentWaitlistPerPage" aria-label="Rows per page"><option>10</option><option>15</option><option>20</option><option>30</option><option>50</option></select></div>
        <div class="resident-waitlist-page-label" id="residentWaitlistPageLabel">Page 1 of 1</div>
        <div class="resident-waitlist-page-btns">
          <button type="button" class="resident-waitlist-page-btn" id="residentWaitlistFirst" aria-label="Go to first page"><i class="fas fa-angle-double-left"></i></button>
          <button type="button" class="resident-waitlist-page-btn" id="residentWaitlistPrev" aria-label="Go to previous page"><i class="fas fa-angle-left"></i></button>
          <button type="button" class="resident-waitlist-page-btn" id="residentWaitlistNext" aria-label="Go to next page"><i class="fas fa-angle-right"></i></button>
          <button type="button" class="resident-waitlist-page-btn" id="residentWaitlistLast" aria-label="Go to last page"><i class="fas fa-angle-double-right"></i></button>
        </div>
      </div>
    </div>
    <div id="residentWaitlistRowMenu" role="menu" aria-label="Waitlist actions"></div>
    <div class="modal" id="residentWaitlistConfirm">
      <div class="sd-dialog-content" role="alertdialog" aria-modal="true" aria-labelledby="residentWaitlistConfirmTitle" aria-describedby="residentWaitlistConfirmMessage">
        <div class="sd-dialog-header">
          <h2 class="sd-dialog-title" id="residentWaitlistConfirmTitle">Cancel waitlist entry?</h2>
          <p class="sd-dialog-description" id="residentWaitlistConfirmMessage">You will no longer receive a notification for this requested slot.</p>
        </div>
        <div class="sd-dialog-footer">
          <button type="button" class="sd-btn sd-btn-outline" id="residentWaitlistCancelConfirm">Keep entry</button>
          <button type="button" class="sd-btn sd-btn-default" id="residentWaitlistConfirmAction">Cancel waitlist</button>
        </div>
      </div>
    </div>
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
        'scheduled'   => ['bg' => '#0E8131', 'color' => '#ffffff', 'border' => '#0E8131'],
        'rescheduled' => ['bg' => '#ede9fe', 'color' => '#6d28d9', 'border' => '#ddd6fe'],
        'completed'   => ['bg' => '#dbeafe', 'color' => '#1e40af', 'border' => '#bfdbfe'],
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
            <th class="sd-th" style="width:40px;"></th>
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
            <td class="sd-td">
              <div class="contact-main contact-normal"><?php echo ucfirst($appt['appointment_type']); ?></div>
              <?php if (($appt['urgency_level'] ?? 'routine') === 'urgent'): ?>
                <span class="appt-status-pill" style="display:inline-block;margin-top:4px;background:#fee2e2;color:#991b1b;border-color:#fecaca;">Urgent</span>
              <?php endif; ?>
            </td>
            <td class="sd-td">
              <div class="client-cell">
                <div class="appt-pet-avatar">
                  <?php if (!empty($appt['pet_photo'])): ?>
                    <img src="<?php echo htmlspecialchars($appt['pet_photo']); ?>" alt="">
                  <?php else: ?>
                    <i class="fas fa-paw" aria-hidden="true"></i>
                  <?php endif; ?>
                </div>
                <div class="contact-main contact-normal"><?php echo htmlspecialchars($appt['pet_name'] ?? '-'); ?></div>
              </div>
            </td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo $aDate; ?></div></td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo $aTime; ?></div></td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo $aCreatedDate; ?></div></td>
            <td class="sd-td"><div class="contact-main contact-normal"><?php echo $aCreatedTime; ?></div></td>
            <td class="sd-td"><span class="appt-status-pill" style="background:<?php echo $asc['bg']; ?>;color:<?php echo $asc['color']; ?>;border-color:<?php echo $asc['border']; ?>;"><?php echo $astLabel; ?></span></td>
            <td class="sd-td sd-td-action" onclick="event.stopPropagation()">
              <button class="sd-row-actions" title="More options" onclick="event.stopPropagation();apptRowActions(event,<?php echo (int)$appt['appointment_id']; ?>)"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div id="apptPagination" class="appt-pagination dt-pagination"></div>
    <?php endif; ?>
  </section>

  <!-- ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"? BOOK AN APPOINTMENT (page) ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"? -->
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
                  <div class="bk-month-nav">
                    <button type="button" id="bkPrevBtn" class="bk-cal-btn" aria-label="Previous month"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>
                    <span class="bk-cal-title" id="bkMonthLabel"></span>
                  </div>
                  <div class="bk-cal-controls">
                    <button type="button" id="bkTodayBtn" class="bk-cal-today">Today</button>
                    <button type="button" id="bkNextBtn" class="bk-cal-btn" aria-label="Next month"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>
                  </div>
                </div>
                <div class="bk-weekdays">
                  <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
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
                    <option value="checkup">Check-up</option>
                    <option value="deworming">Deworming</option>
                    <option value="vaccination">Vaccination</option>
                  </select>
                  <svg class="bk-select-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                </div>
              </div>
              <div class="bk-field">
                <label class="bk-label" for="apptUrgencySelect">Appointment Priority <span class="req">*</span></label>
                <div class="bk-select">
                  <select id="apptUrgencySelect" name="urgency_level" required>
                    <option value="routine">Routine</option>
                    <option value="urgent">Urgent</option>
                  </select>
                  <svg class="bk-select-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                </div>
              </div>
            </div>

            <div id="bkUrgentCareNotice" hidden style="margin:12px 0;padding:12px 14px;border:1px solid #fecaca;border-radius:8px;background:#fef2f2;color:#991b1b;font-size:13px;line-height:1.5;">
              <strong>Urgent care:</strong> For an animal bite or possible rabies exposure, go to an animal bite center or hospital immediately. Do not wait for an appointment.
              For other urgent help, call <a href="tel:+63464321234" style="color:inherit;font-weight:700;">(046) 432-1234</a> or visit the DCVS office.
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
              <div id="bkProgramNotice" hidden style="margin:0 0 10px;padding:11px 13px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;color:#1e3a8a;font-size:13px;line-height:1.5;"></div>
              <div class="bk-slot-grid" id="bkSlots"></div>
              <div id="bkNearestSlotNotice" hidden role="status" aria-live="polite" style="margin-top:10px;font-size:13px;color:#52525b;"></div>
              <div id="bkWaitlistWrap" hidden style="margin-top:10px;">
                <button type="button" class="sd-btn sd-btn-outline" id="bkJoinWaitlist">Notify me if a slot opens</button>
                <div id="bkWaitlistStatus" role="status" aria-live="polite" style="margin-top:6px;font-size:13px;"></div>
              </div>
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
      <input type="hidden" name="urgency_level" id="cfUrgency">
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
            <p class="sd-label">Appointment Information</p>
            <div class="bk-confirm-details">
              <div class="bk-detail-row"><span>Date</span><strong id="bkDetailDate">-</strong></div>
              <div class="bk-detail-row"><span>Time</span><strong id="bkDetailTime">-</strong></div>
              <div class="bk-detail-row"><span>Service Type</span><strong id="bkDetailType">-</strong></div>
              <div class="bk-detail-row"><span>Priority</span><strong id="bkDetailUrgency">Routine</strong></div>
              <div class="bk-detail-row"><span>Available For</span><strong id="bkDetailAvailableFor">-</strong></div>
              <div class="bk-detail-row"><span>Service Fee</span><strong>Free / No Fee</strong></div>
              <div class="bk-detail-row"><span>Estimated Duration</span><strong>30-60 minutes</strong></div>
              <div class="bk-detail-row"><span>Service Location</span><strong>Dasmari&ntilde;as City Veterinary Services</strong></div>
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
      <div class="archive-toolbar">
        <div class="search-grow-wrap">
          <svg class="archive-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input type="text" id="archiveSearch" class="archive-search-input" placeholder="Search archived items..." onkeyup="filterArchive()">
        </div>
        <select class="archive-filter-select archive-detail-filter" id="archiveDetailFilter" onchange="archiveDetailChanged()" aria-label="Archive type filter"></select>
      </div>
    </div>

    <?php
    $archiveRows = [];
    foreach ($archivedPets as $pet) {
        $archiveRows[] = [
            'category' => 'pet',
            'subtype' => strtolower($pet['pet_type'] ?? 'other'),
            'id' => $pet['reference_id'] ?? '#',
            'name' => $pet['pet_name'] ?? '-',
            'type' => ucfirst($pet['pet_type'] ?? '-'),
            'breed' => $pet['breed'] ?? '-',
            'gender' => ucfirst($pet['gender'] ?? '-'),
            'age' => (string)($pet['age'] ?? '-') . ' yrs',
            'location' => '-',
            'date' => !empty($pet['created_at']) ? date('M d, Y', strtotime($pet['created_at'])) : '-',
            'status' => 'Archived',
            'photo' => $pet['photo_url'] ?? '',
            'archived' => !empty($pet['archived_at']) ? date('M d, Y', strtotime($pet['archived_at'])) : '-',
        ];
    }
    foreach ($archivedReports as $report) {
        $archiveRows[] = [
            'category' => 'report',
            'subtype' => strtolower($report['report_type'] ?? 'other'),
            'id' => $report['reference_id'] ?? '#',
            'name' => $report['report_type'] ?? '-',
            'type' => $report['report_type'] ?? '-',
            'breed' => '-',
            'gender' => '-',
            'age' => '-',
            'location' => $report['location_address'] ?? '-',
            'date' => !empty($report['created_at']) ? date('M d, Y', strtotime($report['created_at'])) : '-',
            'status' => ucfirst($report['status'] ?? '-'),
          'photo' => $report['photo_url'] ?? '',
          'video' => $report['video_url'] ?? '',
            'archived' => !empty($report['archived_at']) ? date('M d, Y', strtotime($report['archived_at'])) : '-',
        ];
    }
    foreach ($archivedAppointments as $appt) {
        $archiveRows[] = [
            'category' => 'appointment',
            'subtype' => strtolower($appt['appointment_type'] ?? 'other'),
            'id' => $appt['reference_id'] ?? '#',
            'name' => ucfirst($appt['appointment_type'] ?? '-'),
            'type' => ucfirst($appt['appointment_type'] ?? '-'),
            'breed' => '-',
            'gender' => '-',
            'age' => '-',
            'location' => $appt['pet_name'] ?? '-',
            'date' => !empty($appt['preferred_date']) ? date('M d, Y', strtotime($appt['preferred_date'])) : '-',
            'status' => ucfirst($appt['status'] ?? '-'),
            'photo' => '',
            'archived' => !empty($appt['archived_at']) ? date('M d, Y', strtotime($appt['archived_at'])) : '-',
            'time' => !empty($appt['preferred_time']) ? date('h:i A', strtotime($appt['preferred_time'])) : '-',
        ];
    }
    ?>
    <div class="archive-unified-table-area appt-db-table-area dt-container">
      <table class="appt-table" id="archiveTable">
        <thead><tr class="sd-tr" id="archiveTableHead"></tr></thead>
        <tbody id="archiveTableBody"></tbody>
      </table>
    </div>
    <div class="appt-pagination dt-pagination" id="archivePagination"></div>
    <script>
      window.__residentArchiveRows = <?php echo json_encode($archiveRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    </script>

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
                <th class="sd-th">Attachment</th>
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
                    <?php endif; ?>
                    <?php if (!empty($report['video_url'])): ?>
                      <button type="button" class="sd-btn sd-btn-outline" onclick="window.open('<?php echo htmlspecialchars($report['video_url']); ?>','_blank')"><i class="fas fa-video"></i> Video</button>
                    <?php endif; ?>
                    <?php if (empty($report['photo_url']) && empty($report['video_url'])): ?>
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

  <!-- ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"? ADOPTION LISTINGS ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"?ï¿½"? -->
  

<?php
// Adoption & Scheduling workspace data (actual database records)
$vetPets = array();
$applicationByListing = array();
$applicationByResultPet = array();
foreach ($residentAdoptionApplications as $application) {
    $applicationListingId = (int)$application['pet_listing_id'];
    $resultingPetId = (int)($application['resulting_pet_id'] ?? 0);
    if ($applicationListingId > 0 && !isset($applicationByListing[$applicationListingId])) {
        $applicationByListing[$applicationListingId] = $application;
    }
    if ($resultingPetId > 0 && !isset($applicationByResultPet[$resultingPetId])) {
        $applicationByResultPet[$resultingPetId] = $application;
    }
}
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
        $adoptionDate = '';
        if (isset($l['pl_status']) && $l['pl_status'] === 'adopted' && !empty($l['pl_adopted_at'])) {
            $adoptionDate = $l['pl_adopted_at'];
        } elseif (isset($l['pl_status']) && $l['pl_status'] === 'adopted' && !empty($l['pl_updated_at'])) {
            $adoptionDate = $l['pl_updated_at'];
        } elseif ($lStatus === 'adopted' && !empty($l['updated_at'])) {
            $adoptionDate = $l['updated_at'];
        }
        $application = $applicationByResultPet[$pid] ?? $applicationByListing[(int)($l['source_listing_id'] ?? 0)] ?? null;
        $applicationProgress = $application
            ? ($application['status'] === 'adopted' ? 'Adopted' : 'Approved')
            : '';
        $applicationDate = $application ? date('M d, Y', strtotime($application['application_date'])) : '';
        $vetPets[] = array(
            'id' => $id,
            'resultingPetId' => $pid,
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
            'applicationProgress' => $applicationProgress,
            'applicationDate' => $applicationDate,
            'applicationPending' => false,
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
            'timeline' => $tl,
            'adoptedDate' => ($adoptionDate !== '') ? date('M d, Y', strtotime($adoptionDate)) : '',
            'readiness' => array(
                'vaccination' => 'Partially vaccinated',
                'health' => 'Cleared',
                'spayNeuter' => 'Completed',
                'temperament' => 'Assessed',
            ),
        );
    }
}
foreach ($residentAdoptionApplications as $application) {
    $resultingPetId = (int)($application['resulting_pet_id'] ?? 0);
    $hasResultingPet = false;
    if ($resultingPetId > 0) {
        foreach ($vetPets as $residentPet) {
            if ((int)($residentPet['resultingPetId'] ?? 0) === $resultingPetId) {
                $hasResultingPet = true;
                break;
            }
        }
    }
    if ($hasResultingPet) {
        continue;
    }
    $applicationStatus = strtolower(trim((string)$application['status']));
    $statusMeta = array(
        'pending' => array('key' => 'reserved', 'progress' => 'Pending Application'),
        'under_review' => array('key' => 'reserved', 'progress' => 'In Progress'),
        'needs_info' => array('key' => 'reserved', 'progress' => 'In Progress'),
        'approved' => array('key' => 'reserved', 'progress' => 'In Progress'),
        'expired' => array('key' => 'available', 'progress' => 'Expired / Unclaimed'),
    );
    if (!isset($statusMeta[$applicationStatus])) {
        continue;
    }
    $ageText = trim((string)($application['age'] ?? ''));
    preg_match('/\d+/', $ageText, $ageMatch);
    $ageNum = isset($ageMatch[0]) ? (int)$ageMatch[0] : 0;
    $species = ucfirst(strtolower(trim((string)($application['species'] ?? ''))));
    $applicationName = trim((string)($application['pet_name'] ?? '')) ?: 'Pet';
    $applicationDate = date('M d, Y', strtotime($application['application_date']));
    $applicationId = (int)$application['application_id'];
    $applicationListingId = (int)$application['pet_listing_id'];
    if ($resultingPetId > 0) {
        $statusMeta[$applicationStatus] = array('key' => 'adopted', 'progress' => 'Adopted');
    }
    $vetPets[] = array(
        'id' => 'APP-' . str_pad((string)$applicationId, 4, '0', STR_PAD_LEFT),
        'resultingPetId' => $resultingPetId,
        'name' => $applicationName,
        'species' => $species ?: 'Other',
        'gender' => !empty($application['gender']) ? ucfirst($application['gender']) : "\u{2014}",
        'age' => $ageText !== '' ? $ageText : "\u{2014}",
        'ageNum' => $ageNum,
        'breed' => trim((string)($application['breed'] ?? '')) ?: "\u{2014}",
        'weight' => "\u{2014}",
        'color' => "\u{2014}",
        'photo' => (string)($application['photo_url'] ?? ''),
        'status' => $statusMeta[$applicationStatus]['key'],
        'applicationProgress' => $statusMeta[$applicationStatus]['progress'],
        'applicationDate' => $applicationDate,
        'applicationPending' => $resultingPetId <= 0,
        'applicationId' => $applicationId,
        'petListingId' => $applicationListingId,
        'registered' => $applicationDate,
        'availableSince' => "\u{2014}",
        'added' => $applicationDate,
        'fee' => "\u{2014}",
        'location' => 'Dasmariñas City Veterinary Services',
        'about' => 'Your adoption application is being processed. Track its progress here.',
        'tags' => array(),
        'requirements' => array(),
        'temperament' => array(),
        'behaviorNotes' => '',
        'staffNotes' => '',
        'healthStatus' => "\u{2014}",
        'vaxNote' => '',
        'lastExam' => "\u{2014}",
        'vet' => "\u{2014}",
        'nextCheckup' => "\u{2014}",
        'vaccines' => array(),
        'conditions' => "\u{2014}",
        'medications' => "\u{2014}",
        'applications' => array(),
        'appointments' => array(),
        'documents' => array(),
        'timeline' => array(array('Adoption application submitted', $applicationDate, 'You', '')),
        'adoptedDate' => '',
        'readiness' => array(
            'vaccination' => 'Partially vaccinated',
            'health' => 'For review',
            'spayNeuter' => 'Not assessed',
            'temperament' => 'Not assessed',
        ),
    );
}
?>
<style>
.cv-btn{display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 16px;border-radius:6px;border:1px solid transparent;font-size:14px;font-weight:500;cursor:pointer;transition:background-color .15s ease,border-color .15s ease,color .15s ease;font-family:'Inter',sans-serif;white-space:nowrap;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);}
.cv-btn svg{width:15px;height:15px;}
.cv-btn-primary{background:#0E8131;color:#fff;}
.cv-btn-primary:hover{background:#0a6a28;}
.cv-btn-outline{background:#ffffff;border-color:#e4e4e7;color:#18181b;}
.cv-btn-outline:hover{background:#f4f4f5;}
.cv-btn-ghost{background:transparent;color:#475569;box-shadow:none;}
.cv-btn-ghost:hover{background:#f1f5f9;}
.cv-btn-sm{height:32px;padding:0 14px;font-size:13px;font-weight:500;}
.cv-field{display:flex;flex-direction:column;gap:6px;}
.cv-field.full{grid-column:1/-1;}
.cv-label{font-size:14px;font-weight:500;color:#18181b;}
.cv-input,.cv-select{width:100%;height:36px;padding:0 12px;border:1px solid #e4e4e7;border-radius:6px;font-size:14px;color:#18181b;background:#fff;outline:none;font-family:'Inter',sans-serif;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);transition:border-color .15s ease,box-shadow .15s ease;}
.cv-input:focus,.cv-select:focus{border-color:#18181b;box-shadow:0 0 0 1px #18181b;}
.cv-select{appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 11px center;padding-right:32px;}
.cv-textarea{width:100%;min-height:86px;padding:10px 12px;border:1px solid #e4e4e7;border-radius:6px;font-size:14px;color:#18181b;resize:vertical;outline:none;font-family:'Inter',sans-serif;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);}
.cv-textarea:focus{border-color:#18181b;box-shadow:0 0 0 1px #18181b;}
/* pagination */
.cv-rows{display:flex;align-items:center;gap:8px;font-size:14px;color:#71717a;}.cv-rows select{height:32px;padding:0 8px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-size:14px;font-family:inherit;cursor:pointer;outline:none;}
.cv-pagination{display:flex;align-items:center;gap:4px;}
.cv-page-btn{height:32px;width:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;border:1px solid #e4e4e7;background:#fff;color:#18181b;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.04);}.cv-page-btn svg{width:14px;height:14px;}
.cv-page-btn:hover:not(:disabled){background:#f4f4f5;}
.cv-page-btn:disabled{opacity:.5;cursor:not-allowed;}
.cv-page-label{width:110px;text-align:center;font-size:14px;font-weight:500;color:#18181b;white-space:nowrap;}
/* empty */
.cv-empty{text-align:center;color:#71717a;padding:48px 20px;font-size:14px;}
.cv-empty svg{width:34px;height:34px;margin:0 auto 10px;display:block;color:#cbd5e1;}
/* table */
.cv-table{width:100%;border-collapse:collapse;}
.cv-table th{text-align:left;font-size:12px;font-weight:500;color:#71717a;padding:8px 10px;border-bottom:1px solid #e4e4e7;}
.cv-table td{padding:11px 10px;font-size:14px;color:#18181b;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.cv-table tr:last-child td{border-bottom:none;}
#adoption .appt-db-table-area{min-width:0;max-width:100%;}
#adoption #cvTable th:first-child,
#adoption #cvTable td:first-child{position:sticky;left:0;z-index:2;background:#fff;}
#adoption #cvTable thead th:first-child{z-index:3;background:#fafafa;}
#adoption #cvTable tbody tr:hover td:first-child{background:#fafafa;}
#cvDrawer .cv-table th{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;}
#cvDrawer .cv-table td{font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;}
/* form */
.cv-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.cv-field{display:flex;flex-direction:column;gap:6px;}
.cv-field.full{grid-column:1/-1;}
.cv-label{font-size:14px;font-weight:500;color:#18181b;}
.cv-input,.cv-select{width:100%;height:36px;padding:0 12px;border:1px solid #e4e4e7;border-radius:6px;font-size:14px;color:#18181b;background:#fff;outline:none;font-family:'Inter',sans-serif;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);transition:border-color .15s ease,box-shadow .15s ease;}
.cv-input:focus,.cv-select:focus{border-color:#18181b;box-shadow:0 0 0 1px #18181b;}
.cv-select{appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 11px center;padding-right:32px;}
.cv-textarea{width:100%;min-height:86px;padding:10px 12px;border:1px solid #e4e4e7;border-radius:6px;font-size:14px;color:#18181b;resize:vertical;outline:none;font-family:'Inter',sans-serif;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);}
.cv-textarea:focus{border-color:#18181b;box-shadow:0 0 0 1px #18181b;}
.cv-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:998;display:none;}
.cv-overlay.open{display:block;}
.cv-drawer{position:fixed;top:0;right:0;bottom:0;width:min(480px,92vw);height:100vh;height:100dvh;background:#fff;z-index:999;box-shadow:-8px 0 40px rgba(15,23,32,.18);transform:translateX(105%);transition:transform .32s cubic-bezier(.32,.72,.25,1);display:flex;flex-direction:column;overflow:hidden;}
.cv-drawer.open{transform:translateX(0);}
.cv-drawer-head{display:flex;align-items:flex-start;gap:14px;padding:22px 24px;flex:0 0 auto;}
.cv-drawer-title{font-family:'Inter',sans-serif;font-size:18px;font-weight:600;color:#18181b;letter-spacing:-0.025em;margin:0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.cv-drawer-ref{font-family:'Inter',sans-serif;font-size:14px;font-weight:400;color:#71717a;margin:3px 0 0;}
.cv-drawer-close{margin-left:auto;width:32px;height:32px;border-radius:6px;background:transparent;border:none;color:#71717a;font-size:16px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:background .15s,color .15s;flex-shrink:0;}
.cv-drawer-close:hover{background:#f4f4f5;color:#18181b;}
.cv-drawer-body{flex:1 1 auto;min-height:0;overflow-y:auto;padding:24px;-webkit-overflow-scrolling:touch;}
.cv-drawer-section-title{font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:600;color:#18181b;text-transform:none;letter-spacing:0;margin:20px 0 12px;}
.cv-drawer-section-title:first-child{margin-top:0;}
.cv-drawer-info{display:grid;grid-template-columns:1fr 1fr;gap:16px 24px;margin-bottom:22px;}
.cv-drawer-info-item .k{font-family:'Inter','Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#71717a;}
.cv-drawer-info-item .v{font-family:'Inter','Segoe UI',sans-serif;font-size:15px;font-weight:400;color:#18181b;margin-top:2px;word-break:break-word;}
.cv-drawer-foot{display:flex;gap:10px;padding:16px 24px;align-items:center;flex:0 0 auto;border-top:1px solid #e4e4e7;background:#fff;}
.cv-drawer-foot .sd-btn{flex:1;justify-content:center;}
/* toast */
.cv-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(20px);background:#18181b;color:#fff;font-size:14px;font-weight:500;padding:11px 18px;border-radius:8px;box-shadow:0 10px 15px -3px rgba(0,0,0,0.3);z-index:400;opacity:0;transition:all .2s;pointer-events:none;display:flex;align-items:center;gap:9px;}
.cv-toast.show{opacity:1;transform:translateX(-50%) translateY(0);}
.cv-toast svg{width:15px;height:15px;color:#6ee7b7;}
@media (max-width:1024px){.cv-detail-top{grid-template-columns:1fr;}.cv-detail-img,.cv-detail-img-c{height:280px;}.cv-grid2{grid-template-columns:1fr;}}
@media (max-width:760px){.cv-detail-meta{grid-template-columns:1fr;}.cv-form-grid{grid-template-columns:1fr;}}
</style>

<!-- adoption table -->
<section id="adoption" class="content-section">

  <div class="appt-card-header">
    <div class="appt-header-right">
      <div class="appt-search-wrap">
        <svg class="appt-search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="cvSearch" class="appt-search-input" placeholder="Search by name, ID, species...">
      </div>
      <select id="cvStatusFilter" class="appt-status-select">
        <option value="all">All Status</option>
        <option value="available">Available</option>
        <option value="pending">Pending Application</option>
        <option value="review">In Progress</option>
        <option value="approved">Approved</option>
        <option value="adopted">Adopted</option>
        <option value="unavailable">Not Available</option>
      </select>
      <select id="cvSpeciesFilter" class="appt-status-select">
        <option value="">All Species</option>
        <option value="Dog">Dog</option>
        <option value="Cat">Cat</option>
      </select>
      <select id="cvSort" class="appt-status-select">
        <option value="recent">Recently Added</option>
        <option value="az">Name A&ndash;Z</option>
        <option value="za">Name Z&ndash;A</option>
        <option value="age">Age</option>
        <option value="status">Status</option>
      </select>
    </div>
  </div>

  <div class="appt-db-table-area dt-container">
    <table class="appt-table" id="cvTable">
      <thead>
        <tr class="sd-tr">
          <th class="sd-th">Pet</th>
          <th class="sd-th">Pet / Application ID</th>
          <th class="sd-th">Species</th>
          <th class="sd-th">Breed</th>
          <th class="sd-th">Gender</th>
          <th class="sd-th">Age</th>
          <th class="sd-th">Adoption Status</th>
          <th class="sd-th">Application Progress</th>
          <th class="sd-th">Submitted / Added</th>
          <th class="sd-th" style="width:40px;"></th>
        </tr>
      </thead>
      <tbody id="cvTableBody"></tbody>
    </table>
  </div>

  <div class="appt-pagination dt-pagination">
    <div class="dt-pagination-right">
      <div class="cv-rows dt-rows"><span>Rows per page</span><select id="cvPerPage"><option value="5">5</option><option value="10" selected>10</option><option value="20">20</option><option value="50">50</option></select></div>
      <div class="cv-page-label dt-page-label" id="cvPageLabel">Page 1 of 1</div>
      <div class="cv-pagination dt-page-btns" id="cvPagination"></div>
    </div>
  </div>

</section>

<div class="cv-overlay" id="cvOverlay"></div>
<aside class="cv-drawer" id="cvDrawer" aria-label="Pet profile">
  <div class="cv-drawer-head">
    <div style="min-width:0;">
      <h2 class="cv-drawer-title" id="cvDrawerTitle">Pet Details</h2>
      <p class="cv-drawer-ref" id="cvDrawerRef"></p>
    </div>
    <button type="button" class="cv-drawer-close" id="cvDrawerClose" aria-label="Close"><i class="fas fa-times"></i></button>
  </div>
  <div class="cv-drawer-body" id="cvDrawerBody"></div>
  <div class="cv-drawer-foot" id="cvDrawerFoot"></div>
</aside>

<!-- Archive Confirm Dialog -->
<div class="sd-dialog-overlay" id="cvArchiveModal" style="position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;display:none;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:8px;box-shadow:0 16px 48px rgba(0,0,0,0.15);width:100%;max-width:400px;padding:24px;">
    <h3 style="font-size:16px;font-weight:600;color:#18181b;margin:0 0 8px;">Archive Pet</h3>
    <p id="cvArchiveDesc" style="font-size:14px;color:#71717a;margin:0 0 20px;line-height:1.5;">Are you sure you want to archive this pet?</p>
    <div style="display:flex;justify-content:flex-end;gap:8px;">
      <button class="sd-btn sd-btn-outline" onclick="closeModal('cvArchiveModal')">Cancel</button>
      <button class="sd-btn sd-btn-danger" id="cvArchiveSubmitBtn" onclick="window.__cvSubmitArchive()"><i class="fas fa-archive"></i> Archive</button>
    </div>
  </div>
</div>

<div class="cv-toast" id="cvToast"></div>

<script>
(function(){
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

  var PETS=<?php echo json_encode($vetPets, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;

  var STATUS_META={available:{label:'Available',icon:'check'},reserved:{label:'Reserved',icon:'hourglass'},pending:{label:'Pending Application',icon:'hourglass'},review:{label:'In Progress',icon:'clipboard'},approved:{label:'Approved',icon:'check'},adopted:{label:'Adopted',icon:'heart'},unavailable:{label:'Not Available',icon:'minus'}};
  var ICONS={edit:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>',chevL:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>',chevR:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>',dblChevL:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>',dblChevR:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 17 11 12 6 7"/><polyline points="13 17 18 12 13 7"/></svg>',check:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',hourglass:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/></svg>',clipboard:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6"/><path d="M9 16h6"/></svg>',heart:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',minus:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>',search:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',cal:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',clock:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',weight:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a4 4 0 0 0-4 4c0 1.5.8 2.8 2 3.5L6 21h12l-4-10.5c1.2-.7 2-2 2-3.5a4 4 0 0 0-4-4z"/></svg>',pin:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',species:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 5.172C10 3.782 8.423 2.679 6.5 3c-2.823.47-4.113 6.006-4 7 .08.703 1.725 1.722 3.656 1 1.261-.472 1.96-1.45 2.344-2.5M14 5.172c0-1.39 1.577-2.493 3.5-2.172 2.823.47 4.113 6.006 4 7-.08.703-1.725 1.722-3.656 1-1.261-.472-1.855-1.45-2.344-2.5M8 14v.5M16 14v.5M11.25 16.25h1.5L12 17l-.75-.75z"/><path d="M4.42 11.247A13.152 13.152 0 0 0 4 14.556C4 18.728 7.582 21 12 21s8-2.272 8-6.444c0-1.061-.162-2.2-.493-3.309m-9.243-6.082A8.801 8.801 0 0 1 12 5c.78 0 1.5.108 2.161.306"/></svg>',breed:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>',plus:'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',chev:'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>',doc:'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',download:'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'};

  var state={status:'all',search:'',sort:'recent',species:'',page:1,perPage:10,activeTab:'overview'};
  var els={};

  function filtered(){
    var q=state.search.toLowerCase();
    var statusFilter=els.statusFilter? els.statusFilter.value:'all';
    var speciesFilter=els.speciesFilter? els.speciesFilter.value:'';
    return PETS.filter(function(p){
      if(statusFilter!=='all' && p.status!==statusFilter) return false;
      if(speciesFilter && p.species!==speciesFilter) return false;
      if(q){ var hay=(p.name+' '+p.id+' '+p.species+' '+p.breed+' '+(p.applicationProgress||'')).toLowerCase(); if(!hay.includes(q)) return false; }
      return true;
    });
  }

  function sorted(arr){
    var a=arr.slice();
    if(state.sort==='az') a.sort(function(x,y){return x.name.localeCompare(y.name);});
    else if(state.sort==='za') a.sort(function(x,y){return y.name.localeCompare(x.name);});
    else if(state.sort==='age') a.sort(function(x,y){return x.ageNum-y.ageNum;});
    else if(state.sort==='status') a.sort(function(x,y){return (STATUS_META[x.status]||{label:x.status}).label.localeCompare((STATUS_META[y.status]||{label:y.status}).label);});
    return a;
  }

  function statusPillHTML(status){
    var colors={available:['#dcebe0','#045e1f','#bbf7d0'],reserved:['#fef3c7','#92400e','#fde68a'],pending:['#fef3c7','#92400e','#fde68a'],review:['#dbeafe','#1d4ed8','#bfdbfe'],adopted:['#eff6ff','#1d4ed8','#bfdbfe'],unavailable:['#fef2f2','#b91c1c','#fecaca']};
    var c=colors[status]||['#f4f4f5','#71717a','#e4e4e7'];
    var m=STATUS_META[status]||{label:status||'Unknown'};
    return '<span class="appt-status-pill" style="background:'+c[0]+';color:'+c[1]+';border-color:'+c[2]+';">'+esc(m.label)+'</span>';
  }

  function renderList(){
    var list=filtered(); var total=list.length;
    var sortable=sorted(list);
    var tableArea=document.getElementById('cvTable')?.closest('.appt-db-table-area');
    if(tableArea) tableArea.style.minHeight=Math.max(480,40+(state.perPage*56))+'px';
    var pages=Math.max(1,Math.ceil(total/state.perPage));
    if(state.page>pages) state.page=pages;
    var start=(state.page-1)*state.perPage; var rows=sortable.slice(start,start+state.perPage);
    if(!rows.length){ els.tableBody.innerHTML='<tr><td colspan="10"><div class="cv-empty">'+ICONS.search+'<div>No pets match your criteria.</div></div></td></tr>'; renderPagination(total,pages); return; }
    els.tableBody.innerHTML=rows.map(function(p){
      var photoHtml=p.photo? '<img src="'+esc(p.photo)+'" alt="">': esc((p.name||'P').charAt(0).toUpperCase());
      return '<tr class="sd-tr" onclick="window.__cvOpenDetail(\''+esc(p.id)+'\')">'+
        '<td class="sd-td"><div class="client-cell"><div class="pet-avatar-box">'+photoHtml+'</div><div class="pet-cell-main"><div class="client-name">'+esc(p.name)+'</div></div></div></td>'+
        '<td class="sd-td"><div class="pet-id-label">'+esc(p.id)+'</div></td>'+
        '<td class="sd-td"><div class="contact-main contact-normal">'+esc(p.species)+'</div></td>'+
        '<td class="sd-td"><div class="contact-main contact-normal">'+esc(p.breed)+'</div></td>'+
        '<td class="sd-td"><div class="contact-main contact-normal">'+esc(p.gender)+'</div></td>'+
        '<td class="sd-td"><div class="contact-main contact-normal">'+esc(p.age)+'</div></td>'+
        '<td class="sd-td">'+statusPillHTML(p.status)+'</td>'+
        '<td class="sd-td"><div class="contact-main contact-normal">'+esc(p.applicationProgress||'\u2014')+'</div></td>'+
        '<td class="sd-td"><div class="contact-main contact-normal">'+esc(p.applicationDate||p.added)+'</div></td>'+
        '<td class="sd-td sd-td-action" onclick="event.stopPropagation()"><button class="sd-row-actions" title="More options" onclick="event.stopPropagation();cvRowActions(event,\''+esc(p.id)+'\')"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>'+
      '</tr>';
    }).join('');
    renderPagination(total,pages);
  }
  function renderPagination(total,pages){
    if(els.pageLabel) els.pageLabel.textContent='Page '+state.page+' of '+pages;
    var h='<button type="button" class="cv-page-btn dt-page-btn" data-p="first" '+(state.page<=1?'disabled':'')+' title="Go to first page">'+ICONS.dblChevL+'</button>';
    h+='<button type="button" class="cv-page-btn dt-page-btn" data-p="prev" '+(state.page<=1?'disabled':'')+' title="Go to previous page">'+ICONS.chevL+'</button>';
    h+='<button type="button" class="cv-page-btn dt-page-btn" data-p="next" '+(state.page>=pages?'disabled':'')+' title="Go to next page">'+ICONS.chevR+'</button>';
    h+='<button type="button" class="cv-page-btn dt-page-btn" data-p="last" '+(state.page>=pages?'disabled':'')+' title="Go to last page">'+ICONS.dblChevR+'</button>';
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

  function openDetailModal(id){
    var p=PETS.find(function(x){return x.id===id;});
    if(!p) return;
    state.activeTab='overview';
    showDetailModal(p);
  }

  function showDetailModal(p){
    var _lbl='font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:600;color:#71717a;margin:0;';
    var _val='font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:400;color:#18181b;margin:0;';
    var _sec='font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:600;color:#18181b;margin:18px 0 10px;';
    function ir(label,value){return '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;margin-bottom:12px;"><div style="display:flex;flex-direction:column;gap:2px;min-width:0;"><p class="info-row-label" style="'+_lbl+'">'+esc(label)+'</p><p class="info-row-value" style="'+_val+'">'+value+'</p></div></div>';}
    function sec(title){return '<div class="rpt-section-title" style="'+_sec+'">'+title+'</div>';}
    var rd=p.readiness||{};
    function rdOk(v){return !!v && v!=='Not specified' && v!=='Not assessed' && v!=='Not vaccinated';}
    var ready=rdOk(rd.vaccination) && rd.health==='Cleared' && rdOk(rd.spayNeuter) && rdOk(rd.temperament);
    function rdRow(label,val){var v=val||'Not specified';return '<tr><td>'+esc(label)+'</td><td>'+esc(v)+(rdOk(v)?' \u2713':'')+'</td></tr>';}
    var body=
      sec('Pet Information')+
      '<div class="rpt-detail-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px 24px;">'+
        ir('Species',esc(p.species))+
        ir('Breed',esc(p.breed))+
        ir('Gender',esc(p.gender))+
        ir('Age',esc(p.age))+
        ir('Status',esc(STATUS_META[p.status] ? STATUS_META[p.status].label : p.status))+
        ir('Pet ID',esc(p.id))+
        ir('Registered',esc(p.registered))+
      '</div>'+
      sec('Details')+
      '<div class="rpt-detail-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px 24px;">'+
        ir(p.applicationPending?'Application Submitted':'Adoption Progress',esc(p.applicationPending?(p.applicationDate||'\u2014'):(p.applicationProgress||p.adoptedDate||'\u2014')))+
        ir('Location',esc(p.location))+
        ir('Health Status',esc(ready?'Cleared':'For review'))+
      '</div>'+
      sec('About')+
      '<p style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:400;color:#71717a;line-height:1.6;margin:0 0 20px;">'+esc(p.about||'No description available.')+'</p>'+
      sec('Vaccination Records')+
      '<table class="cv-table"><thead><tr><th>Check</th><th>Status</th></tr></thead><tbody>'+
        rdRow('Vaccination Status',rd.vaccination)+
        rdRow('Health / Medical Status',rd.health)+
        rdRow('Spay / Neuter',rd.spayNeuter)+
        rdRow('Temperament',rd.temperament)+
      '</tbody></table>'+
      sec('Activity Timeline')+
      (p.timeline.length? p.timeline.map(function(x){return '<div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:14px;"><div style="width:8px;height:8px;border-radius:50%;background:#0E8131;margin-top:6px;flex-shrink:0;"></div><div style="flex:1;min-width:0;"><div style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:500;color:#18181b;">'+esc(x[0])+'</div><div style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;color:#71717a;margin-top:2px;">by '+esc(x[2])+(x[3]?' \u00b7 '+esc(x[3]):'')+' \u00b7 '+esc(x[1])+'</div></div></div>';}).join('') : '<p style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:400;color:#71717a;margin:0;">No activity recorded.</p>');

    els.drawerTitle.textContent='Pet Details';
    els.drawerRef.textContent='View and review this pet\u2019s adoption information, care history, and current records.';
    var petPhoto=p.photo? '<img src="'+esc(p.photo)+'" alt="" style="width:85px;height:85px;border-radius:8px;object-fit:cover;">' : '<div style="width:85px;height:85px;border-radius:8px;background:#e4e4e7;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:28px;color:#64748b;">'+esc((p.name||'P').charAt(0).toUpperCase())+'</div>';
    var statusColors={available:['#dcebe0','#045e1f','#bbf7d0'],pending:['#fef3c7','#92400e','#fde68a'],review:['#dbeafe','#1d4ed8','#bfdbfe'],approved:['#dcebe0','#045e1f','#bbf7d0'],adopted:['#eff6ff','#1d4ed8','#bfdbfe'],unavailable:['#fef2f2','#b91c1c','#fecaca']};
    var adoptColors=statusColors[p.status]||['#f4f4f5','#71717a','#e4e4e7'];
    var statusLabel=STATUS_META[p.status]?STATUS_META[p.status].label:p.status;
    var adoptPill='<span style="font-size:13px;font-weight:500;color:'+adoptColors[1]+';background:'+adoptColors[0]+';border:1px solid '+adoptColors[2]+';padding:4px 12px;border-radius:6px;font-family:Inter,sans-serif;white-space:nowrap;">'+esc(statusLabel)+'</span>';
    var header='<div class="cv-pet-summary" style="margin-bottom:14px;display:flex;align-items:center;gap:12px;">'+
      '<div class="cv-pet-summary-photo" style="flex-shrink:0;">'+petPhoto+'</div>'+
      '<div class="cv-pet-summary-content" style="display:flex;flex-direction:column;gap:2px;min-width:0;">'+
        '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:12px;font-weight:400;color:#71717a;">'+(p.applicationPending?'Adoption application':'Adoption status')+'</span>'+
        '<span class="cv-pet-summary-name" style="font-family:Inter,Segoe UI,sans-serif;font-size:18px;font-weight:600;color:#18181b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+esc(p.name)+'</span>'+
        (p.status==='adopted'&&p.adoptedDate? '<span style="font-family:Inter,Segoe UI,sans-serif;font-size:14px;font-weight:600;color:#71717a;margin-top:2px;">Adopted on '+esc(p.adoptedDate)+'</span>':'')+
      '</div>'+
      '<span class="cv-pet-summary-status">'+adoptPill+'</span>'+
    '</div>';
    els.drawerBody.innerHTML=header+body;
    els.drawerFoot.innerHTML='<button type="button" class="sd-btn sd-btn-outline" onclick="window.__cvCloseDrawer()">Close</button>'+
      (p.applicationPending?'':'<button type="button" class="sd-btn sd-btn-danger" onclick="window.__cvArchive(\''+esc(p.id)+'\',\''+esc(p.name)+'\')"><i class="fas fa-archive"></i> Archive</button>');
    openDrawer();
  }

  function metaItem(icon,label,val,html){
    return '<div style="display:flex;gap:11px;align-items:flex-start;"><div style="width:30px;height:30px;color:#18181b;display:flex;align-items:center;justify-content:center;flex-shrink:0;">'+icon+'</div><div><div style="font-family:Inter,sans-serif;font-size:13px;font-weight:500;color:#18181b;">'+esc(label)+'</div><div style="font-family:Inter,sans-serif;font-size:14px;font-weight:400;color:#18181b;margin-top:1px;">'+(html?val:esc(val))+'</div></div></div>';
  }

  function setStatus(p,status){ p.status=status; renderList(); toast('Marked as '+STATUS_META[status].label); }

  function openBook(p){
    var types=['Meet & Greet','Adoption Consultation','Home Visit','Medical Check','Vaccination','Follow-up','Adoption Finalization'];
    var body=
      '<div class="cv-drawer-section-title">Book Appointment</div>'+
      '<div class="cv-drawer-info">'+
        '<div class="cv-drawer-info-item" style="grid-column:1/-1;"><div class="k">Pet</div><div class="v">'+esc(p.name)+'</div></div>'+
        '<div class="cv-drawer-info-item" style="grid-column:1/-1;"><div class="k">Appointment Type</div><div class="v"><select class="cv-select" style="width:100%;">'+types.map(function(t){return '<option>'+esc(t)+'</option>';}).join('')+'</select></div></div>'+
        '<div class="cv-drawer-info-item"><div class="k">Date</div><div class="v"><input class="cv-input" type="date" value="2026-09-08" style="width:100%;"></div></div>'+
        '<div class="cv-drawer-info-item"><div class="k">Time</div><div class="v"><select class="cv-select" style="width:100%;"><option>10:00 AM</option><option>11:00 AM</option><option>02:00 PM</option><option>04:00 PM</option></select></div></div>'+
        '<div class="cv-drawer-info-item" style="grid-column:1/-1;"><div class="k">Notes</div><div class="v"><textarea class="cv-textarea" placeholder="Add any notes..." style="width:100%;min-height:80px;"></textarea></div></div>'+
      '</div>';
    els.drawerTitle.textContent='Book Appointment';
    els.drawerRef.textContent='';
    els.drawerBody.innerHTML=body;
    els.drawerFoot.innerHTML='<button type="button" class="sd-btn sd-btn-default" onclick="window.__cvCloseDrawer()">Cancel</button><button type="button" class="sd-btn sd-btn-primary" onclick="window.__cvCloseDrawer();window.__cvToast(\'Appointment scheduled for '+esc(p.name)+'\')">Schedule</button>';
    openDrawer();
  }
  var bodyOverflowBeforeDrawer = null;
  function openDrawer(){
    if (!els.drawer.classList.contains('open')) bodyOverflowBeforeDrawer = document.body.style.overflow;
    els.overlay.classList.add('open');
    els.drawer.classList.add('open');
    document.body.style.overflow='hidden';
    document.addEventListener('keydown',window.__cvEscHandler);
  }
  function closeDrawer(){
    els.overlay.classList.remove('open');
    els.drawer.classList.remove('open');
    document.body.style.overflow = bodyOverflowBeforeDrawer === null ? '' : bodyOverflowBeforeDrawer;
    bodyOverflowBeforeDrawer = null;
    document.removeEventListener('keydown',window.__cvEscHandler);
  }

  window.__cvCloseDrawer=function(){ closeDrawer(); };
  window.__cvEscHandler=function(e){ if(e.key==='Escape') closeDrawer(); };

  var _archiveId='';
  window.__cvArchive=function(id,name){
    _archiveId=id;
    document.getElementById('cvArchiveDesc').textContent='Are you sure you want to archive "'+name+'"? This pet will be removed from your active listings.';
    openModal('cvArchiveModal');
  };
  window.__cvSubmitArchive=function(){
    var p=PETS.find(function(x){return x.id===_archiveId;});
    if(p) p.status='archived';
    closeModal('cvArchiveModal');
    closeDrawer();
    renderList();
    toast('Pet archived successfully');
  };

  window.__cvEdit=function(id){
    var p=PETS.find(function(x){return x.id===id;});
    if(!p) return;
    document.getElementById('editPetId').value=id;
    document.getElementById('editPetName').value=p.name||'';
    document.getElementById('editPetSpecies').value=p.speciesRaw||'';
    document.getElementById('editPetBreed').value=(p.breed==='\u2014'?'':p.breed)||'';
    document.getElementById('editPetGender').value=p.genderRaw||'male';
    document.getElementById('editPetAge').value=p.ageNum||'';
    document.getElementById('editPetWeight').value=p.weightNum||'';
    document.getElementById('editPetColor').value=(p.color==='\u2014'?'':p.color)||'';
    document.getElementById('editPetVaccinated').value=p.vaccinated||'no';
    var photoDiv=document.getElementById('editPetCurrentPhoto');
    if(p.photo){photoDiv.style.display='flex';document.getElementById('editPetCurrentPhotoImg').src=p.photo;}else{photoDiv.style.display='none';}
    var speciesCustom=document.getElementById('editPetSpeciesCustom');
    if(speciesCustom) speciesCustom.style.display='none';
    openModal('editPetModal');
  };

  function toast(msg){ els.toast.innerHTML=ICONS.check+' '+esc(msg); els.toast.classList.add('show'); clearTimeout(toast._t); toast._t=setTimeout(function(){els.toast.classList.remove('show');},2200); }

  window.__cvOpenDetail=function(id){ openDetailModal(id); };

  var _activeCvMenu=null;
  function closeCvMenu(){if(_activeCvMenu){_activeCvMenu.remove();_activeCvMenu=null;}document.removeEventListener('click',closeCvMenu);}
  window.closeCvMenu=closeCvMenu;
  window.cvRowActions=function(ev,id){
    closeCvMenu();ev.stopPropagation();
    var p=PETS.find(function(x){return x.id===id;});if(!p)return;
    var btn=ev.currentTarget;var r=btn.getBoundingClientRect();
    var eye='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var trash='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>';
    var items=[];
    items.push(_mi(eye,'View Details','','window.__cvOpenDetail(\''+esc(id)+'\');closeCvMenu()'));
    if(!p.applicationPending){
      items.push('<div style="height:1px;background:#e4e4e7;margin:4px 0;"></div>');
      items.push(_mi(trash,'Archive','#b91c1c','window.__cvArchive(\''+esc(id)+'\',\''+esc(p.name)+'\');closeCvMenu()'));
    }
    var menu=_buildRowMenu(items);
    _positionMenu(menu,btn);
  };

  function _buildRowMenu(items){
    var menu=document.createElement('div');
    menu.className='row-menu-pop resident-row-menu';
    menu.innerHTML=items.join('');
    return menu;
  }
  function _positionMenu(menu,btn){
    var r=btn.getBoundingClientRect();
    document.body.appendChild(menu);
    var menuWidth=menu.offsetWidth||190;
    var menuHeight=menu.offsetHeight||180;
    var left=Math.max(8,Math.min(r.right-menuWidth,window.innerWidth-menuWidth-8));
    var top=r.bottom+4;
    if(top+menuHeight>window.innerHeight-8 && r.top-menuHeight-4>=8) top=r.top-menuHeight-4;
    menu.style.left=left+'px';
    menu.style.top=top+'px';
    _activeCvMenu=menu;
    setTimeout(function(){document.addEventListener('click',closeCvMenu);},0);
  }
  function _mi(icon,label,color,onclick){
    var danger=color==='red'?' danger':'';
    return '<button class="row-menu-item'+danger+'" onclick="'+onclick+'">'+icon+' '+label+'</button>';
  }

  window.petRowActions=function(ev,id){
    closeCvMenu();ev.stopPropagation();
    var tr=document.querySelector('#petTable tr[data-id="'+id+'"]');
    var isAdopted=tr?tr.getAttribute('data-cat')==='adopted':false;
    var pName=tr?tr.querySelector('.client-name'):null;
    var name=pName?pName.textContent.trim():'this pet';
    var eye='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var doc='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
    var edit='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>';
    var trash='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>';
    var items=[];
    items.push(_mi(eye,'View Profile','','PET.view('+id+');closeCvMenu()'));
    items.push(_mi(doc,'Pet Resume','','window.open(\'pet-profile.php?id='+id+'&ref=resident\',\'_blank\');closeCvMenu()'));
    if(!isAdopted) items.push(_mi(edit,'Edit','','PET.edit('+id+');closeCvMenu()'));
    items.push('<div style="height:1px;background:#e4e4e7;margin:4px 0;"></div>');
    items.push(_mi(trash,'Archive','#b91c1c','PET.archive('+id+');closeCvMenu()'));
    var menu=_buildRowMenu(items);
    _positionMenu(menu,ev.currentTarget);
  };

  window.rptRowActions=function(ev,id){
    closeCvMenu();ev.stopPropagation();
    var tr=document.querySelector('#reportTable tr[data-id="'+id+'"]');
    var eye='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var trash='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>';
    var items=[];
items.push(_mi(eye,'View Report','','RPT.view('+id+');closeCvMenu()'));
items.push(_mi(trash,'Archive','red','RPT.archive('+id+');closeCvMenu()'));
    var menu=_buildRowMenu(items);
    _positionMenu(menu,ev.currentTarget);
  };

  window.apptRowActions=function(ev,id){
    closeCvMenu();ev.stopPropagation();
    var tr=document.querySelector('#apptTable tr[data-id="'+id+'"]');
    var status=tr?tr.getAttribute('data-status'):'';
    var edit='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
    var trash='<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>';
    var items=[];
    if (status === 'pending') items.push(_mi(edit,'Edit','','APPT.editById('+id+');closeCvMenu()'));
    items.push('<div style="height:1px;background:#e4e4e7;margin:4px 0;"></div>');
    var archiveLabel = status === 'scheduled' || status === 'completed' ? 'Archive' : 'Cancel';
    items.push(_mi(trash,archiveLabel,'red','APPT.archive('+id+');closeCvMenu()'));
    var menu=_buildRowMenu(items);
    _positionMenu(menu,ev.currentTarget);
  };

  function init(){
    els.tableBody=document.getElementById('cvTableBody');
    els.pagination=document.getElementById('cvPagination'); els.pageLabel=document.getElementById('cvPageLabel'); els.perPage=document.getElementById('cvPerPage');
    els.search=document.getElementById('cvSearch'); els.sort=document.getElementById('cvSort'); els.overlay=document.getElementById('cvOverlay');
    els.statusFilter=document.getElementById('cvStatusFilter'); els.speciesFilter=document.getElementById('cvSpeciesFilter');
    els.drawer=document.getElementById('cvDrawer'); els.drawerTitle=document.getElementById('cvDrawerTitle'); els.drawerRef=document.getElementById('cvDrawerRef'); els.drawerBody=document.getElementById('cvDrawerBody'); els.drawerFoot=document.getElementById('cvDrawerFoot');
    els.toast=document.getElementById('cvToast');
    els.drawerClose=document.getElementById('cvDrawerClose');
    els.drawerClose.addEventListener('click',closeDrawer);
    els.overlay.addEventListener('click',function(e){ if(e.target===els.overlay) closeDrawer(); });
    els.search.addEventListener('input',function(){ state.search=els.search.value; state.page=1; renderList(); });
    els.sort.addEventListener('change',function(){ state.sort=els.sort.value; state.page=1; renderList(); });
    els.perPage.addEventListener('change',function(){ state.perPage=parseInt(els.perPage.value,10)||10; state.page=1; renderList(); });
    if(els.statusFilter) els.statusFilter.addEventListener('change',function(){ state.page=1; renderList(); });
    if(els.speciesFilter) els.speciesFilter.addEventListener('change',function(){ state.page=1; renderList(); });
    renderList();
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
</script>

<section id="account-settings" class="content-section">

    <?php if (!empty($profileError)): ?>
      <div id="profileError" style="text-align:center;margin:0 auto 14px;font-size:13px;color:#991b1b;"><?php echo htmlspecialchars($profileError); ?></div>
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

<!-- ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½
     MODALS
ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ï¿½.ï¿½ -->

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
        <input type="hidden" name="adopt_listing_id" value="<?php echo (int)($adoptListingId ?? 0); ?>" />
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
            <p class="adoption-readonly-value"><?php echo htmlspecialchars($adoptData['name'] ?? ''); ?></p>
          <?php else: ?>
            <input type="text" name="pet_name" class="sd-input" placeholder="e.g. Max" required />
          <?php endif; ?>
        </div>
        <div class="sd-field">
          <label class="sd-label">Species <span class="req">*</span></label>
          <?php if ($showAdoptModal): ?>
            <p class="adoption-readonly-value"><?php echo ucfirst(htmlspecialchars($adoptData['species'] ?? '')); ?></p>
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
            <p class="adoption-readonly-value"><?php echo htmlspecialchars($adoptData['breed'] ?? '-'); ?></p>
          <?php else: ?>
            <input type="text" name="breed" class="sd-input" placeholder="e.g. Aspin" />
          <?php endif; ?>
        </div>
        <div class="sd-field">
          <label class="sd-label">Gender <span class="req">*</span></label>
          <?php if ($showAdoptModal): ?>
            <p class="adoption-readonly-value"><?php echo ucfirst(htmlspecialchars($adoptData['gender'] ?? '')); ?></p>
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
            <p class="adoption-readonly-value"><?php echo htmlspecialchars((string)($adoptData['age'] ?? '')); ?> yr<?php echo ($adoptData['age'] ?? 0) > 1 ? 's' : ''; ?></p>
          <?php else: ?>
            <input type="number" name="age" min="0" class="sd-input" placeholder="e.g. 2" />
          <?php endif; ?>
        </div>
        <div class="sd-field">
          <label class="sd-label">Weight (kg)</label>
          <?php if ($showAdoptModal): ?>
            <p class="adoption-readonly-value"><?php echo htmlspecialchars((string)($adoptData['weight'] ?? '')); ?> kg</p>
          <?php else: ?>
            <input type="number" name="weight" step="0.1" class="sd-input" placeholder="e.g. 5.5" />
          <?php endif; ?>
        </div>
      </div>
      <div class="sd-field sd-field-full">
        <label class="sd-label">Color / Markings</label>
        <?php if ($showAdoptModal): ?>
          <p class="adoption-readonly-value"><?php echo htmlspecialchars($adoptData['color'] ?? '-'); ?></p>
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
          <div class="adoption-readonly-photo">
            <img src="<?php echo htmlspecialchars($adoptData['photo']); ?>" alt="<?php echo htmlspecialchars($adoptData['name'] ?? 'Pet'); ?>">
          </div>
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
          <input type="file" name="photo" accept="image/*" id="addPetPhoto" onclick="event.stopPropagation()" onchange="previewPetImage(this, 'addPetPhotoBox', 'addPetPhotoImg')" />
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
            <input type="file" name="photo" accept="image/*" id="editPetPhoto" onclick="event.stopPropagation()" onchange="previewPetImage(this, 'editPetPhotoBox', 'editPetPhotoImg')" />
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
      var tableArea = document.getElementById('reportTable')?.closest('.appt-db-table-area');
      if (tableArea) tableArea.style.minHeight = Math.max(480, 40 + (PER_PAGE * 56)) + 'px';
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
      <p class="rpt-drawer-ref" id="rdHeadType">Review report information and case details.</p>
    </div>
    <button type="button" class="rpt-drawer-close" onclick="closeReportDrawer()" aria-label="Close"><i class="fas fa-times"></i></button>
  </div>
  <div class="rpt-drawer-body">

    <div class="rpt-tabs">
      <button type="button" class="rpt-tab active" data-tab="details" onclick="rdDetailGo('details')">Details</button>
      <button type="button" class="rpt-tab" data-tab="attachments" onclick="rdDetailGo('attachments')">Attachments</button>
    </div>

    <div class="rpt-detail-body active" data-tab="details">
      <p class="pet-drawer-section-title">Report Information</p>
      <div class="pet-drawer-info">
        <div class="pet-drawer-info-item"><div class="k">Date Submitted</div><div class="v" id="rdDate"></div></div>
        <div class="pet-drawer-info-item"><div class="k">Incident Date</div><div class="v" id="rdIncidentDate"></div></div>
        <div class="pet-drawer-info-item"><div class="k">Location</div><div class="v" id="rdLocation"></div></div>
        <div class="pet-drawer-info-item"><div class="k">Report Type</div><div class="v" id="rdReportType"></div></div>
      </div>

      <p class="pet-drawer-section-title">Animal Information</p>
      <div class="pet-drawer-info">
        <div class="pet-drawer-info-item"><div class="k">Animal Type</div><div class="v" id="rdAnimalType"></div></div>
        <div class="pet-drawer-info-item"><div class="k">Breed / Appearance</div><div class="v" id="rdAnimalBreed"></div></div>
        <div class="pet-drawer-info-item"><div class="k">Estimated Number of Animals</div><div class="v" id="rdAnimalCount"></div></div>
        <div class="pet-drawer-info-item"><div class="k">Animal Behavior</div><div class="v" id="rdAnimalBehavior"></div></div>
      </div>

      <p class="pet-drawer-section-title">Incident Details</p>
      <div class="pet-drawer-info">
        <div class="pet-drawer-info-item pet-drawer-info-item-full"><div class="k">Description</div><div class="v" id="rdIncident"></div></div>
      </div>

      <p class="pet-drawer-section-title">Status</p>
      <div id="rdTimeline" style="margin-bottom:1rem;"></div>
      <div class="rpt-detail-block" id="rdResolution" style="display:none;margin-top:0.75rem;">
        <div class="pet-drawer-info-item"><div class="k">Resolution</div><div class="v" id="rdResolutionText"></div></div>
      </div>

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

    <div class="rpt-detail-body" data-tab="attachments">
      <p class="pet-drawer-section-title">Attached Photos & Videos</p>
      <div class="pet-drawer-info">
        <div class="pet-drawer-info-item pet-drawer-info-item-full"><div class="v"><div class="rpt-photos" id="rdPhotos"></div></div></div>
      </div>
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
  // Animal values submitted by the multi-step form (see submit-report-page.php)
  $knownAnimalTypes = ['dog', 'cat', 'bird', 'horse', 'goat', 'other'];
  foreach ($reports as $report) {
      $rawDescription = trim((string)($report['description'] ?? ''));
      $hasStructuredFields = ($report['animal_type'] ?? '') !== '';
      // Legacy reports stored the animal fields in one description string.
      $animalType = (string)($report['animal_type'] ?? '');
      $animalBreed = (string)($report['animal_breed'] ?? '');
      $animalCount = (string)($report['animal_count'] ?? '');
      $animalBehavior = (string)($report['animal_behavior'] ?? '');
      $incidentDescription = $rawDescription;
      if (!$hasStructuredFields && $rawDescription !== '') {
          $segments = preg_split('/\s+-\s+/', $rawDescription, 5);
          if (count($segments) === 5
              && in_array(strtolower(trim($segments[0])), $knownAnimalTypes, true)
              && preg_match('/^(\d+|\d+\s*-\s*\d+|\d+\+)$/', trim($segments[2]))) {
              $animalType = trim($segments[0]);
              $animalBreed = trim($segments[1]);
              $animalCount = trim($segments[2]);
              $animalBehavior = trim($segments[3]);
              $incidentDescription = trim($segments[4]);
          }
      }
      $rt = strtolower($report['report_type']);
      $jsonReports[] = [
          'id' => (int)$report['report_id'],
          'ref' => $report['reference_id'] ?? '#RPT-' . str_pad($report['report_id'], 4, '0', STR_PAD_LEFT),
          'type' => ($knownReportTypes[$rt] ?? ucfirst($report['report_type'])) . ' Report',
          'typeLabel' => $knownReportTypes[$rt] ?? ucfirst($report['report_type']),
          'typeKey' => $rt,
          'urgency' => $report['urgency_level'] ?? 'medium',
          'createdRaw' => date('Y-m-d\TH:i', strtotime($report['created_at'])),
          'incidentRaw' => !empty($report['incident_at']) ? date('Y-m-d\TH:i', strtotime($report['incident_at'])) : date('Y-m-d\TH:i', strtotime($report['created_at'])),
          'location' => $report['location_address'] ?? '-',
          'date' => date('F d, Y', strtotime($report['created_at'])),
          'incidentDate' => date('F d, Y', strtotime(!empty($report['incident_at']) ? $report['incident_at'] : $report['created_at'])),
          'description' => $rawDescription,
          'animalType' => $animalType,
          'animalBreed' => $animalBreed,
          'animalCount' => $animalCount,
          'animalBehavior' => $animalBehavior,
          'incidentDescription' => $incidentDescription,
          'photo' => $report['photo_url'] ?? '',
          'video' => $report['video_url'] ?? '',
          'status' => $report['status'],
          'statusLabel' => rptStatusLabel($report['status']),
          'statusClass' => rptStatusClass($report['status']),
          'resolution' => (string)($report['resolution_details'] ?? ''),
          'responseState' => (string)($report['response_state'] ?? ''),
          'responseMode' => (string)($report['response_mode'] ?? ''),
          'responseDeadline' => (string)($report['response_deadline'] ?? ''),
          'progressDeadline' => (string)($report['progress_deadline'] ?? ''),
          'turnoverDeadline' => (string)($report['turnover_deadline'] ?? ''),
          'holdingLocation' => (string)($report['holding_location'] ?? ''),
          'animalDisposition' => (string)($report['animal_disposition'] ?? ''),
          'turnoverStatus' => (string)($report['turnover_status'] ?? ''),
          'turnoverDueAt' => (string)($report['turnover_due_at'] ?? ''),
          'turnoverReceivedAt' => (string)($report['turnover_received_at'] ?? ''),
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
    var title = document.getElementById('rdNextTitle');
    var list = document.getElementById('rdNextList');
    if (!title || !list) return;
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
    title.innerHTML = '<i class="fas fa-info-circle"></i> ' + titleText;
    list.innerHTML = items.map(function(t) { return '<li>' + t + '</li>'; }).join('');
  }

  var rdTab = 'details';
  function rdRefreshTabs() {
    document.querySelectorAll('#rptDrawer .rpt-tab').forEach(function(b) {
      b.classList.toggle('active', b.getAttribute('data-tab') === rdTab);
    });
    document.querySelectorAll('#rptDrawer .rpt-detail-body').forEach(function(b) {
      b.classList.toggle('active', b.getAttribute('data-tab') === rdTab);
    });
  }
  window.rdDetailGo = function(t) { rdTab = t; rdRefreshTabs(); };

  window.openReportDetails = function(id) {
    var r = reportIndex[id];
    if (!r) return;
    var set = function(eid, val) { var e = document.getElementById(eid); if (e) e.textContent = val; };
    var orDash = function(val) {
      var s = (val === null || val === undefined) ? '' : String(val).trim();
      return s === '' ? '-' : s;
    };
    set('rdDate', orDash(r.date));
    set('rdIncidentDate', orDash(r.incidentDate || r.date));
    set('rdLocation', orDash(r.location));
    set('rdReportType', orDash(r.typeLabel));
    set('rdAnimalType', orDash(r.animalType));
    set('rdAnimalBreed', orDash(r.animalBreed));
    set('rdAnimalCount', orDash(r.animalCount));
    set('rdAnimalBehavior', orDash(r.animalBehavior));
    set('rdIncident', orDash(r.incidentDescription));
    var badge = document.getElementById('rdBadge');
    if (badge) badge.className = 'rpt-status-badge ' + r.statusClass;
    set('rdBadgeText', r.statusLabel);
    var tlEl = document.getElementById('rdTimeline');
    if (tlEl) {
      var responseLabels = {
        awaiting_dcvs_decision:'Pending DCVS review', dcvs_dispatch:'DCVS team assigned',
        barangay_response_approved:'Barangay response approved', barangay_responding:'Barangay is responding',
        animal_secured:'Animal secured by barangay', awaiting_turnover:'Awaiting turnover to DCVS',
        turned_over:'Turned over to DCVS', dcvs_processing:'DCVS processing',
        unable_to_locate:'Unable to locate', escalated_to_dcvs:'Escalated to DCVS',
        returned_to_barangay:'Returned to barangay', closed_not_actionable:'Closed — not actionable',
        resolved:'Incident resolved'
      };
      var responseLabel = responseLabels[r.responseState];
      var turnoverLabels = { pending:'For Turnover — awaiting DCVS receipt', receipt_confirmed:'Turnover Completed', cancelled:'Turnover Cancelled' };
      var turnoverLabel = turnoverLabels[r.turnoverStatus] || '';
      var nextDeadline = r.responseDeadline || r.progressDeadline || '';
      var deadlineOverdue = nextDeadline && new Date(nextDeadline.replace(' ', 'T')).getTime() < Date.now();
      tlEl.innerHTML = buildTimeline(r.status) + (responseLabel
        ? '<div class="rpt-response-tracker" role="status" style="margin-top:12px;padding:12px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff;color:#1e3a8a;font-size:14px;font-weight:500;">' +
          '<strong>Case update:</strong> ' + responseLabel +
          (r.status === 'resolved' && turnoverLabel ? '<br><span style="font-weight:500;">' + turnoverLabel + '</span>' : '') +
          (r.status !== 'resolved' && nextDeadline ? '<br><span style="font-weight:400;' + (deadlineOverdue ? 'color:#b91c1c;' : '') + '">' +
            (deadlineOverdue ? 'Response deadline overdue: ' : 'Response target: ') + nextDeadline + '</span>' : '') +
          (r.holdingLocation && ['animal_secured','awaiting_turnover','turned_over'].indexOf(r.responseState) !== -1
            ? '<br><span style="font-weight:400;">Holding location: ' + r.holdingLocation.replace(/[&<>"']/g, function (character) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]); }) + '</span>' : '') +
          (r.turnoverReceivedAt ? '<br><span style="font-weight:400;">DCVS receipt confirmed: ' + r.turnoverReceivedAt + '</span>' : '') +
          '</div>' : '');
    }
    var resolved = r.status === 'resolved';
    var res = document.getElementById('rdResolution');
    if (resolved) {
      res.style.display = '';
      set('rdResolutionText', r.resolution);
    } else {
      res.style.display = 'none';
    }
    applyNextSteps(r.status);
    var attachmentHtml = '';
    if (r.photo) {
      attachmentHtml += '<div class="rpt-photo-thumb"><img src="' + r.photo + '" alt="Report photo" onclick="viewReportImage(\'' + r.photo + '\')"></div>';
    }
    if (r.video) {
      attachmentHtml += '<div class="rpt-photo-thumb rpt-video-thumb"><video src="' + r.video + '" controls playsinline preload="metadata"></video></div>';
    }
    document.getElementById('rdPhotos').innerHTML = attachmentHtml || '<p class="rpt-photo-none">No attachments.</p>';
    rdTab = 'details';
    rdRefreshTabs();
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
      var tableArea = document.getElementById('petTable')?.closest('.appt-db-table-area');
      if (tableArea) tableArea.style.minHeight = Math.max(480, 40 + (PER_PAGE * 56)) + 'px';
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
      var report = reportIndex[window.__currentReportId];
      if (report) {
        try {
          sessionStorage.setItem('dcvsResubmitReport', JSON.stringify(report));
        } catch (e) {}
      }
      closeReportDrawer();
      window.location.href = 'submit-report-page.php?resubmit=1';
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
  window.RPT.refreshSummary = computeSummary;
  computeSummary();
})();
</script>

<script>

// ï¿½"?ï¿½"? Appointments table (search/filter/sort/paginate) ï¿½"?ï¿½"?
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
    var tableArea = document.getElementById('apptTable')?.closest('.appt-db-table-area');
    if (tableArea) tableArea.style.minHeight = Math.max(480, 40 + (PER_PAGE * 56)) + 'px';
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
  window.fillAppointmentEditForm = function(a) {
    a = a || {};
    var set = function(id, v) { var el = document.getElementById(id); if (el) el.value = (v == null ? '' : v); };
    var ensureOption = function(selId, value, label) {
      var sel = document.getElementById(selId);
      if (!sel || !value) return;
      if (!sel.querySelector('option[value="' + value + '"]')) {
        var o = document.createElement('option');
        o.value = value;
        o.textContent = label || value;
        sel.appendChild(o);
      }
    };
    var type = String(a.service || '').toLowerCase();
    var time = String(a.timeRaw || '').substring(0, 5);
    ensureOption('editAppointmentType', type, a.service || type);
    ensureOption('editAppointmentTime', time, time);
    set('editAppointmentId', a.id != null ? a.id : '');
    set('editAppointmentType', type);
    set('editAppointmentUrgency', a.urgency || 'routine');
    set('editAppointmentPet', a.petId != null ? a.petId : '');
    set('editAppointmentDate', a.dateRaw || '');
    set('editAppointmentTime', time);
    set('editAppointmentNotes', (a.notes && a.notes !== '-') ? a.notes : '');
  };
  window.APPT.editById = function(id) {
    var a = getAppt(id);
    if (!a || a.status !== 'pending') return;
    window.openAppointmentForm(a, 'edit');
  };
  window.APPT.rescheduleById = function(id) {
    var a = getAppt(id);
    if (!a || (a.status !== 'scheduled' && a.status !== 'rescheduled')) return;
    window.openAppointmentForm(a, 'reschedule');
  };
  window.openAppointmentForm = function(a, action) {
    window.fillAppointmentEditForm(a);
    var title = document.querySelector('#editAppointmentModal .sd-dialog-title');
    var description = document.querySelector('#editAppointmentModal .sd-dialog-description');
    var submit = document.querySelector('#editAppointmentForm button[type="submit"]');
    var isReschedule = action === 'reschedule';
    if (title) title.textContent = isReschedule ? 'Reschedule Appointment' : 'Edit Appointment';
    if (description) description.textContent = isReschedule
      ? 'Choose a new date and time for your scheduled appointment.'
      : 'Update your pending appointment request details below.';
    if (submit) submit.innerHTML = isReschedule
      ? '<i class="fas fa-calendar-alt"></i> Reschedule Appointment'
      : '<i class="fas fa-save"></i> Update Appointment';
    openModal('editAppointmentModal');
  };
  var apptColors = {
    'pending': ['#fef3c7', '#92400e'],
    'scheduled': ['#0E8131', '#ffffff', '#0E8131'],
    'rescheduled': ['#ede9fe', '#6d28d9'],
    'completed': ['#dbeafe', '#1e40af', '#bfdbfe'],
    'cancelled': ['#fee2e2', '#991b1b'],
    'no_show': ['#fef3c7', '#92400e']
  };

  window.APPT.view = function(id) {
    var a = getAppt(id);
    if (!a) return;
    window.__currentApptId = a.id;
    var set = function(eid, val) { var e = document.getElementById(eid); if (e) e.textContent = val; };
    set('adHeadPet', a.pet);
    var avEl = document.getElementById('adAvatar');
    if (avEl) {
      if (a.petPhoto) {
        avEl.innerHTML = '<img src="' + String(a.petPhoto).replace(/"/g, '&quot;') + '" alt="" style="width:100%;height:100%;object-fit:cover;">';
      } else {
        avEl.textContent = (String(a.pet || '?').replace(/[^A-Za-z0-9]/g, '').substring(0, 2) || '?').toUpperCase();
      }
    }
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
    var hp = document.getElementById('adHeadStatus');
    if (hp) {
      hp.textContent = a.statusLabel;
      hp.style.background = c[0];
      hp.style.color = c[1];
      hp.style.borderColor = c[2] || '#e4e4e7';
    }
    var nextEl = document.getElementById('adNextList');
    if (nextEl) {
      var steps = apptNextSteps(a.status);
      nextEl.innerHTML = steps.map(function(t) { return '<li>' + t + '</li>'; }).join('');
    }
    var changeButton = document.getElementById('apptChangeBtn');
    if (changeButton) {
      var canEdit = a.status === 'pending';
      changeButton.style.display = canEdit ? '' : 'none';
      changeButton.textContent = 'Edit Appointment';
      changeButton.setAttribute('data-action', 'edit');
    }
    var archiveButton = document.getElementById('apptArchiveBtn');
    if (archiveButton) {
      var canArchiveOrCancel = ['pending', 'scheduled', 'rescheduled', 'completed'].indexOf(a.status) !== -1;
      archiveButton.style.display = canArchiveOrCancel ? '' : 'none';
      archiveButton.textContent = a.status === 'scheduled' || a.status === 'completed' ? 'Archive Appointment' : 'Cancel Appointment';
    }
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
    if (!a || a.status !== 'pending') return;
    closeApptDrawer();
    window.openAppointmentForm(a, 'edit');
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
    if (tb2) { table = t2; tbody = tb2; allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr')); }
    apptIndex = null;
    currentPage = 1;
    renderTable();
    computeSummary();
  };
  function createPetCell(petName, photo) {
    var cell = document.createElement('td');
    cell.className = 'sd-td';
    var wrapper = document.createElement('div');
    wrapper.className = 'client-cell';
    var avatar = document.createElement('div');
    avatar.className = 'appt-pet-avatar';
    if (photo) {
      var image = document.createElement('img');
      image.src = photo;
      image.alt = '';
      image.addEventListener('error', function() {
        avatar.innerHTML = '<i class="fas fa-paw" aria-hidden="true"></i>';
      });
      avatar.appendChild(image);
    } else {
      avatar.innerHTML = '<i class="fas fa-paw" aria-hidden="true"></i>';
    }
    var name = document.createElement('div');
    name.className = 'contact-main contact-normal';
    name.textContent = petName || '-';
    wrapper.append(avatar, name);
    cell.appendChild(wrapper);
    return cell;
  }
  window.APPT.addRequest = function(appt) {
    var section = document.getElementById('appointments');
    if (!section || !appt) return;
    var tableEl = document.getElementById('apptTable');
    if (!tableEl) {
      var empty = section.querySelector('.rpt-empty');
      if (empty) empty.remove();
      var area = document.createElement('div');
      area.className = 'appt-db-table-area dt-container';
      area.innerHTML = '<table class="appt-table" id="apptTable"><thead><tr class="sd-tr"><th class="sd-th">Request ID</th><th class="sd-th">Service</th><th class="sd-th">Pet</th><th class="sd-th">Preferred Date</th><th class="sd-th">Preferred Time</th><th class="sd-th">Submitted Date</th><th class="sd-th">Submitted Time</th><th class="sd-th">Status</th><th class="sd-th"></th></tr></thead><tbody></tbody></table><div id="apptPagination" class="dt-pagination"></div>';
      section.appendChild(area);
      tableEl = document.getElementById('apptTable');
      tbody = tableEl.querySelector('tbody');
    }

    var id = Number(appt.appointment_id);
    if (!id || tableEl.querySelector('tr[data-id="' + id + '"]')) return;
    var row = document.createElement('tr');
    row.className = 'sd-tr';
    row.setAttribute('data-id', String(id));
    row.setAttribute('data-status', appt.status || 'pending');
    row.setAttribute('data-service', (appt.appointment_type || '').toLowerCase());
    row.setAttribute('data-pet', (appt.pet_name || '-').toLowerCase());
    row.setAttribute('data-date', String(Math.floor(new Date(appt.preferred_date).getTime() / 1000)));
    row.setAttribute('data-time', String(Math.floor(new Date('1970-01-01T' + appt.preferred_time).getTime() / 1000)));
    row.setAttribute('data-submitted', String(Math.floor(new Date(String(appt.created_at).replace(' ', 'T')).getTime() / 1000)));
    row.onclick = function() { window.APPT.view(id); };
    var type = (appt.appointment_type || '').charAt(0).toUpperCase() + (appt.appointment_type || '').slice(1);
    var when = new Date(appt.preferred_date + 'T' + appt.preferred_time);
    var submitted = new Date(String(appt.created_at).replace(' ', 'T'));
    var dateText = when.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
    var timeText = when.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });
    var submittedDate = submitted.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
    var submittedTime = submitted.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });
    var cells = [
      appt.reference_id || ('#APT-' + String(id).padStart(4, '0')),
      type
    ];
    cells.forEach(function(value) {
      var td = document.createElement('td');
      td.className = 'sd-td';
      var text = document.createElement('div');
      text.className = 'contact-main contact-normal';
      text.textContent = value;
      td.appendChild(text);
      row.appendChild(td);
    });
    row.appendChild(createPetCell(appt.pet_name, appt.pet_photo));
    var trailingCells = [
      dateText,
      timeText,
      submittedDate,
      submittedTime
    ];
    trailingCells.forEach(function(value) {
      var td = document.createElement('td');
      td.className = 'sd-td';
      var text = document.createElement('div');
      text.className = 'contact-main contact-normal';
      text.textContent = value;
      td.appendChild(text);
      row.appendChild(td);
    });
    var statusCell = document.createElement('td');
    statusCell.className = 'sd-td';
    var badge = document.createElement('span');
    badge.className = 'appt-status-pill';
    badge.textContent = 'Pending';
    badge.style.cssText = 'background:#fef3c7;color:#92400e;border-color:#fde68a;';
    statusCell.appendChild(badge);
    row.appendChild(statusCell);
    var actionCell = document.createElement('td');
    actionCell.className = 'sd-td sd-td-action';
    var action = document.createElement('button');
    action.type = 'button';
    action.className = 'sd-row-actions';
    action.title = 'More options';
    action.textContent = '...';
    action.onclick = function(event) { event.stopPropagation(); window.APPT.view(id); };
    actionCell.appendChild(action);
    row.appendChild(actionCell);
    tbody.insertBefore(row, tbody.firstChild);

    window.__apptList = window.__apptList || [];
    window.__apptList.unshift({
      id: id,
      ref: appt.reference_id || ('#APT-' + String(id).padStart(4, '0')),
      service: type,
      pet: appt.pet_name || '-',
      petId: appt.pet_id || '',
      petPhoto: appt.pet_photo || '',
      date: dateText,
      time: timeText,
      dateRaw: appt.preferred_date,
      timeRaw: appt.preferred_time,
      created: submittedDate,
      createdTime: submittedTime,
      status: 'pending',
      statusLabel: 'Pending',
      notes: appt.notes || '-'
    });
    window.APPT.refresh();
  };

  window.APPT.updateFromRealtime = function(appt) {
    if (!appt || !appt.appointment_id) return;
    var id = Number(appt.appointment_id);
    var row = document.querySelector('#apptTable tr[data-id="' + id + '"]');
    if (!row) {
      window.APPT.addRequest(appt);
      return;
    }
    var status = appt.status || 'pending';
    var type = (appt.appointment_type || '').charAt(0).toUpperCase() + (appt.appointment_type || '').slice(1);
    var dt = new Date(appt.preferred_date + 'T' + appt.preferred_time);
    var dateText = dt.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
    var timeText = dt.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });
    row.setAttribute('data-status', status);
    row.setAttribute('data-service', (appt.appointment_type || '').toLowerCase());
    row.setAttribute('data-pet', (appt.pet_name || '-').toLowerCase());
    row.setAttribute('data-date', String(Math.floor(dt.getTime() / 1000)));
    var cells = row.querySelectorAll('td');
    if (cells[1]) cells[1].textContent = type;
    var currentAppt = (window.__apptList || []).find(function(entry) { return Number(entry.id) === id; });
    if (cells[2]) cells[2].replaceWith(createPetCell(appt.pet_name, appt.pet_photo || (currentAppt && currentAppt.petPhoto) || ''));
    if (cells[3]) cells[3].textContent = dateText;
    if (cells[4]) cells[4].textContent = timeText;
    var badge = row.querySelector('.appt-status-pill');
    var colors = { pending:['#fef3c7','#92400e','#fde68a'], scheduled:['#0E8131','#ffffff','#0E8131'], rescheduled:['#ede9fe','#6d28d9','#ddd6fe'], completed:['#dbeafe','#1e40af','#bfdbfe'], cancelled:['#fee2e2','#991b1b','#fecaca'], no_show:['#fef3c7','#92400e','#fde68a'] };
    var style = colors[status] || ['#f1f5f9','#64748b','#e4e4e7'];
    if (badge) {
      badge.textContent = status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ');
      badge.style.background = style[0];
      badge.style.color = style[1];
      badge.style.borderColor = style[2];
    }
    var list = window.__apptList || [];
    var item = list.find(function(entry) { return Number(entry.id) === id; });
    if (item) Object.assign(item, { service:type, pet:appt.pet_name || '-', petPhoto:appt.pet_photo || item.petPhoto || '', date:dateText, time:timeText, dateRaw:appt.preferred_date, timeRaw:appt.preferred_time, status:status, statusLabel:badge ? badge.textContent : status });
    window.APPT.refresh();
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

<!-- Submit Report Modal (removed â€” now uses submit-report-page.php) -->

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
            <?php foreach (array_diff_key($knownReportTypes, ['other' => 1]) as $val => $label): ?>
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
      <p class="rpt-drawer-ref">Review details of this appointment request.</p>
    </div>
    <button type="button" class="rpt-drawer-close" onclick="closeApptDrawer()" aria-label="Close"><i class="fas fa-times"></i></button>
  </div>
  <div class="rpt-drawer-body">
    <div style="margin-bottom:24px;display:flex;align-items:center;gap:12px;">
      <div id="adAvatar" style="width:85px;height:85px;border-radius:8px;flex-shrink:0;overflow:hidden;background:#e4e4e7;display:flex;align-items:center;justify-content:center;font-family:Inter,Segoe UI,sans-serif;font-weight:600;font-size:28px;color:#64748b;">
        <i class="fas fa-paw"></i>
      </div>
      <div style="display:flex;flex-direction:column;gap:2px;min-width:0;">
        <span style="font-family:Inter,Segoe UI,sans-serif;font-size:12px;font-weight:400;color:#71717a;">Appointed for</span>
        <span id="adHeadPet" style="font-family:Inter,Segoe UI,sans-serif;font-size:18px;font-weight:600;color:#18181b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">-</span>
      </div>
      <span id="adHeadStatus" style="margin-left:auto;font-size:13px;font-weight:500;padding:4px 12px;border-radius:6px;border:1px solid #e4e4e7;font-family:Inter,sans-serif;white-space:nowrap;">-</span>
    </div>
    <p class="pet-drawer-section-title">Appointment Information</p>
    <div class="pet-drawer-info">
      <div class="pet-drawer-info-item"><div class="k">Request ID</div><div class="v" id="adRef"></div></div>
      <div class="pet-drawer-info-item"><div class="k">Service</div><div class="v" id="adService"></div></div>
      <div class="pet-drawer-info-item"><div class="k">Pet</div><div class="v" id="adPet"></div></div>
      <div class="pet-drawer-info-item"><div class="k">Status</div><div class="v" id="adStatus"></div></div>
      <div class="pet-drawer-info-item"><div class="k">Appointment Date</div><div class="v" id="adDate"></div></div>
      <div class="pet-drawer-info-item"><div class="k">Appointment Time</div><div class="v" id="adTime"></div></div>
      <div class="pet-drawer-info-item pet-drawer-info-item-full"><div class="k">Submitted</div><div class="v" id="adCreated"></div></div>
      <div class="pet-drawer-info-item pet-drawer-info-item-full"><div class="k">Notes</div><div class="v appt-notes" id="adNotes"></div></div>
    </div>

    <div class="rpt-next">
      <div class="rpt-next-title"><i class="fas fa-info-circle"></i> What Happens Next?</div>
      <ol id="adNextList"></ol>
    </div>
  </div>
  <div class="rpt-drawer-foot">
    <button type="button" id="apptChangeBtn" class="sd-btn sd-btn-outline" onclick="APPT.editFromDrawer()">Edit Appointment</button>
    <button type="button" id="apptArchiveBtn" class="sd-btn sd-btn-outline" style="border-color:#fecaca;color:#b91c1c;" onclick="APPT.archive(window.__currentApptId)">Cancel Appointment</button>
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
          'urgency' => $appt['urgency_level'] ?? 'routine',
          'pet' => $appt['pet_name'] ?? '-',
          'petId' => $appt['pet_id'] ?? '',
          'petPhoto' => $appt['pet_photo'] ?? '',
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
            <option value="checkup">Check-up</option>
            <option value="deworming">Deworming</option>
            <option value="vaccination">Vaccination</option>
          </select>
        </div>
        <div class="sd-field">
          <label class="sd-label">Priority</label>
          <select name="urgency_level" id="editAppointmentUrgency" class="sd-input">
            <option value="routine">Routine</option>
            <option value="urgent">Urgent</option>
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
  #confirmModal .sd-dialog-title { margin:0; font-family:'Inter',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
  #confirmModal .sd-dialog-description { margin:0; font-family:'Inter',sans-serif; font-size:14px; color:#71717a; line-height:1.5; }
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

function previewPetImage(input, previewId, imageId) {
  var preview = document.getElementById(previewId);
  var image = document.getElementById(imageId);
  if (!preview || !image || !input.files || !input.files[0]) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    image.src = e.target.result;
    image.style.display = 'block';
    preview.classList.add('has-image');
    var placeholder = preview.querySelector('.upload-placeholder');
    if (placeholder) placeholder.style.display = 'none';
  };
  reader.readAsDataURL(input.files[0]);
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
    window.openAppointmentForm({
        id: btn.getAttribute('data-appointment-id'),
        service: btn.dataset.appointmentType || '',
        petId: btn.dataset.petId || '',
        dateRaw: btn.dataset.preferredDate || '',
        timeRaw: btn.dataset.preferredTime || '',
        notes: btn.dataset.notes || ''
    }, 'edit');
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

var archivePage = 1;
var archivePerPage = 10;
var archiveCategory = 'pet';

function archiveEscape(value) {
  return String(value == null ? '' : value).replace(/[&<>"']/g, function(c) {
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
  });
}

function archiveStatusPill(status) {
  var key = String(status || '').toLowerCase().replace(/\s+/g, '_');
  var colors = {
    archived: ['#f1f5f9', '#475569', '#e2e8f0'],
    resolved: ['#0E8131', '#ffffff', '#0E8131'],
    scheduled: ['#0E8131', '#ffffff', '#0E8131'],
    completed: ['#dbeafe', '#1e40af', '#bfdbfe'],
    pending: ['#fef3c7', '#92400e', '#fde68a'],
    cancelled: ['#fee2e2', '#991b1b', '#fecaca']
  };
  var c = colors[key] || ['#f4f4f5', '#52525b', '#e4e4e7'];
  return '<span class="appt-status-pill" style="background:' + c[0] + ';color:' + c[1] + ';border-color:' + c[2] + ';">' + archiveEscape(status || '-') + '</span>';
}

function archivePhotoCell(row) {
  var html = '';
  if (row.photo) html += '<button type="button" class="sd-btn sd-btn-outline" data-archive-photo="' + archiveEscape(row.photo) + '" data-archive-category="' + row.category + '"><i class="fas fa-image"></i> View</button>';
  if (row.video) html += (html ? ' ' : '') + '<button type="button" class="sd-btn sd-btn-outline" data-archive-video="' + archiveEscape(row.video) + '" data-archive-category="' + row.category + '"><i class="fas fa-video"></i> Video</button>';
  if (!html) return '<span style="color:#9ca3af;font-size:0.85rem;">No photo</span>';
  return html;
}

function archiveColumns(category) {
  if (category === 'pet') return ['ID', 'Name', 'Species', 'Breed', 'Gender', 'Age', 'Registered', 'Archived', 'Photo'];
  if (category === 'report') return ['ID', 'Type', 'Location', 'Submitted', 'Status', 'Attachment', 'Archived'];
  if (category === 'appointment') return ['ID', 'Type', 'Appointment Date', 'Time', 'Status', 'Archived'];
  return ['Category', 'ID', 'Type', 'Details', 'Date', 'Status', 'Archived'];
}

function archiveRowCells(row, category) {
  if (category === 'pet') return [row.id, row.name, row.type, row.breed, row.gender, row.age, row.date, row.archived, archivePhotoCell(row)];
  if (category === 'report') return [row.id, row.type, row.location, row.date, archiveStatusPill(row.status), archivePhotoCell(row), row.archived];
  if (category === 'appointment') return [row.id, row.type, row.date, row.time || '-', archiveStatusPill(row.status), row.archived];
  return [category === 'all' ? (row.category === 'pet' ? 'Pets' : row.category === 'report' ? 'Reports' : 'Appointments') : row.category, row.id, row.type, row.location || row.name, row.date, archiveStatusPill(row.status), row.archived];
}

function renderArchiveTable(rows, category) {
  var tableArea = document.querySelector('#archive .archive-unified-table-area');
  var head = document.getElementById('archiveTableHead');
  var body = document.getElementById('archiveTableBody');
  var pagination = document.getElementById('archivePagination');
  if (!tableArea || !head || !body || !pagination) return;
  tableArea.style.minHeight = Math.max(480, 40 + (archivePerPage * 56)) + 'px';
  head.innerHTML = archiveColumns(category).map(function(label) { return '<th class="sd-th">' + label + '</th>'; }).join('');
  var pages = Math.max(1, Math.ceil(rows.length / archivePerPage));
  if (archivePage > pages) archivePage = pages;
  var pageRows = rows.slice((archivePage - 1) * archivePerPage, archivePage * archivePerPage);
  if (!pageRows.length) {
    body.innerHTML = '<tr class="archive-empty-row"><td colspan="' + archiveColumns(category).length + '"><i class="fas fa-archive"></i>No archived ' + (category === 'all' ? 'records' : category + ' records') + '.</td></tr>';
  } else {
    body.innerHTML = pageRows.map(function(row) {
      return '<tr class="sd-tr">' + archiveRowCells(row, category).map(function(cell) { return '<td class="sd-td">' + (cell && String(cell).indexOf('<') === 0 ? cell : archiveEscape(cell)); }).join('') + '</tr>';
    }).join('');
  }
  body.querySelectorAll('[data-archive-photo]').forEach(function(button) {
    button.addEventListener('click', function() {
      var url = button.getAttribute('data-archive-photo');
      if (button.getAttribute('data-archive-category') === 'pet' && typeof viewPetImage === 'function') viewPetImage(url);
      else if (typeof viewReportImage === 'function') viewReportImage(url);
    });
  });
  body.querySelectorAll('[data-archive-video]').forEach(function(button) {
    button.addEventListener('click', function() {
      window.open(button.getAttribute('data-archive-video'), '_blank');
    });
  });
  pagination.innerHTML = '<div class="dt-pagination-right">' +
    '<div class="dt-rows"><span>Rows per page</span><select onchange="setArchivePerPage(this.value)">' +
    [10,15,20,30,50].map(function(n) { return '<option value="' + n + '"' + (archivePerPage === n ? ' selected' : '') + '>' + n + '</option>'; }).join('') +
    '</select></div><div class="dt-page-label">Page ' + archivePage + ' of ' + pages + '</div><div class="dt-page-btns">' +
    '<button type="button" class="dt-page-btn" onclick="archiveGoTo(1)"' + (archivePage <= 1 ? ' disabled' : '') + ' title="Go to first page"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>' +
    '<button type="button" class="dt-page-btn" onclick="archiveGoTo(' + (archivePage - 1) + ')"' + (archivePage <= 1 ? ' disabled' : '') + ' title="Go to previous page"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>' +
    '<button type="button" class="dt-page-btn" onclick="archiveGoTo(' + (archivePage + 1) + ')"' + (archivePage >= pages ? ' disabled' : '') + ' title="Go to next page"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>' +
    '<button type="button" class="dt-page-btn" onclick="archiveGoTo(' + pages + ')"' + (archivePage >= pages ? ' disabled' : '') + ' title="Go to last page"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></button>' +
    '</div></div>';
}

var archiveSubtypeOptions = {
  all: [
    ['all', 'All Types']
  ],
  pet: [
    ['all', 'All Species'], ['dog', 'Dog'], ['cat', 'Cat'], ['bird', 'Bird'], ['other', 'Other']
  ],
  report: [
    ['all', 'All Types'], ['stray', 'Stray Animal'], ['cruelty', 'Animal Cruelty'], ['injury', 'Injured Animal'], ['rabies', 'Rabies Suspect']
  ],
  appointment: [
    ['all', 'All Types'], ['vaccination', 'Vaccination'], ['checkup', 'Check-up'], ['sterilization', 'Sterilization']
  ]
};

function archiveNormalizeType(value) {
  return String(value || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');
}

function archiveSubtypeMatches(row, category, subtype) {
  if (!subtype || subtype === 'all') return true;
  var value = archiveNormalizeType(row.subtype);
  if (category === 'report') {
    var reportAliases = {
      stray: ['stray', 'stray_animal'], cruelty: ['cruelty', 'animal_cruelty'], injury: ['injury', 'injured', 'injured_animal'],
      rabies: ['rabies', 'rabies_suspect']
    };
    return (reportAliases[subtype] || [subtype]).indexOf(value) !== -1;
  }
  if (category === 'appointment') {
    var appointmentAliases = { checkup: ['checkup', 'check_up'], sterilization: ['sterilization', 'kapon'] };
    return (appointmentAliases[subtype] || [subtype]).indexOf(value) !== -1;
  }
  return value === subtype || (subtype === 'other' && !['dog', 'cat', 'bird', 'rabbit'].includes(value));
}

function updateArchiveDetailOptions() {
  var category = archiveCategory;
  var select = document.getElementById('archiveDetailFilter');
  if (!select) return;
  select.innerHTML = (archiveSubtypeOptions[category || 'all'] || archiveSubtypeOptions.all).map(function(option) {
    return '<option value="' + option[0] + '">' + option[1] + '</option>';
  }).join('');
}

function archiveCategoryChanged() {
  updateArchiveDetailOptions();
  archivePage = 1;
  filterArchive();
}

function archiveDetailChanged() {
  var category = archiveCategory;
  var detail = document.getElementById('archiveDetailFilter')?.value || 'all';
  archivePage = 1;
  filterArchive();
}

function getFilteredArchiveRows() {
  var q = (document.getElementById('archiveSearch')?.value || '').toLowerCase().trim();
  var category = archiveCategory;
  var subtype = document.getElementById('archiveDetailFilter')?.value || 'all';
  return (window.__residentArchiveRows || []).filter(function(row) {
    var categoryMatch = !category || row.category === category;
    var subtypeMatch = archiveSubtypeMatches(row, category, subtype);
    var searchMatch = !q || Object.keys(row).some(function(key) { return String(row[key] || '').toLowerCase().includes(q); });
    return categoryMatch && subtypeMatch && searchMatch;
  });
}

function filterArchive() {
  var category = archiveCategory;
  var rows = getFilteredArchiveRows();
  archivePage = 1;
  renderArchiveTable(rows, category || 'all');
}

function setArchivePerPage(value) {
  archivePerPage = parseInt(value, 10) || 10;
  archivePage = 1;
  filterArchive();
}

function archiveGoTo(page) {
  var category = archiveCategory;
  var rows = getFilteredArchiveRows();
  var pages = Math.max(1, Math.ceil(rows.length / archivePerPage));
  archivePage = Math.max(1, Math.min(page, pages));
  renderArchiveTable(rows, category || 'all');
}

function setArchiveType(type) {
  archiveCategory = type || '';
  updateArchiveDetailOptions();
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

updateArchiveDetailOptions();
filterArchive();

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
    var urgencyEl = prefix ? document.getElementById('editAppointmentUrgency') : document.getElementById('apptUrgencySelect');
    var urgency = urgencyEl ? urgencyEl.value : 'routine';
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
    fetch('includes/check_slot.php?date=' + encodeURIComponent(date) + '&time=' + encodeURIComponent(time) + '&exclude=' + exclude + '&urgency=' + encodeURIComponent(urgency))
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var submitBtn = form ? form.querySelector('button[type="submit"]') : null;
            if (d.error) {
                status.style.background = '#fef2f2';
                status.style.color = '#991b1b';
                status.textContent = d.error;
                if (submitBtn) { submitBtn.disabled = true; submitBtn.style.opacity = '0.5'; }
                return;
            }
            if (d.available) {
                status.style.background = '#f0fdf4';
                status.style.color = '#065f46';
                status.textContent = 'Slot available';
                if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = '1'; }
            } else {
                status.style.background = '#fef2f2';
                status.style.color = '#991b1b';
                var msg = d.program_blocked ? 'All clinic staff are assigned to a program during this time.'
                                    : (d.clinic_blocked ? 'This time slot is closed by the clinic schedule.'
                                    : (d.held ? 'This time slot is already reserved by another resident.'
                                              : 'This slot is no longer available.'));
                if (d.nearest_slot) {
                    var nearest = d.nearest_slot.split(' ');
                    var nearDate = new Date(nearest[0] + 'T00:00:00');
                    msg += ' Nearest available: ' + nearDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' at ' + nearest[1];
                }
                status.textContent = msg;
                if (submitBtn) { submitBtn.disabled = true; submitBtn.style.opacity = '0.5'; }
            }
        })
        .catch(function(error) {
            console.error('Appointment slot availability check failed:', error);
            status.style.display = 'block';
            status.style.background = '#fef2f2';
            status.style.color = '#991b1b';
            status.textContent = 'Could not verify this appointment slot. Please try again.';
            var submitBtn = form ? form.querySelector('button[type="submit"]') : null;
            if (submitBtn) { submitBtn.disabled = true; submitBtn.style.opacity = '0.5'; }
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
    ['apptUrgencySelect', 'editAppointmentUrgency'].forEach(function(id) {
        var urgency = document.getElementById(id);
        if (urgency) urgency.addEventListener('change', checkSlot);
    });
});

// ï¿½"?ï¿½"? Booking card (Coach Scheduling Card style) inside Request Appointment modal ï¿½"?ï¿½"?
(function() {
    var BK_SLOTS = ['09:00', '10:00', '11:00', '14:00', '15:00'];
    var bkMonthLabel = document.getElementById('bkMonthLabel');
    var bkGrid = document.getElementById('bkGridDates');
    var bkPrev = document.getElementById('bkPrevBtn');
    var bkNext = document.getElementById('bkNextBtn');
    var bkToday = document.getElementById('bkTodayBtn');
    var bkSlots = document.getElementById('bkSlots');
    var bkSlotsHint = document.getElementById('bkSlotsHint');
    var bkNextStep = document.getElementById('bkNextStep');
    if (!bkGrid) return;

    var today = new Date();
    var viewDate = new Date(today.getFullYear(), today.getMonth(), 1);
    var selStart = null;      // ISO 'YYYY-MM-DD' (selected calendar date)
    var chosenDate = null;    // ISO 'YYYY-MM-DD' of the picked time slot
    var selectedTime = null;  // 'HH:MM'
    var routineSlots = {};
    var urgentSlots = {};
    var routineCapacity = {};
    var urgentCapacity = {};
    var blockedSlots = {};
    var urgentBlockedSlots = {};
    var programEvents = {};
    var dateOverrides = {};
    var maxAppointmentsPerDay = 3;
    var maxUrgentPerDay = 2;
    var availabilityError = '';
    var waitlistWrap = document.getElementById('bkWaitlistWrap');
    var waitlistButton = document.getElementById('bkJoinWaitlist');
    var waitlistStatus = document.getElementById('bkWaitlistStatus');
    var nearestSlotNotice = document.getElementById('bkNearestSlotNotice');
    var residentWaitlistEntries = <?php echo json_encode($residentWaitlist, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var bookingPrefill = <?php echo json_encode($residentWaitlistBooking, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var urgencySelect = document.getElementById('apptUrgencySelect');
    var typeSelect = document.getElementById('apptTypeSelect');
    var nearestRequestId = 0;

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

    function selectedUrgency() {
        return urgencySelect && urgencySelect.value === 'urgent' ? 'urgent' : 'routine';
    }
    function getSlotCounts(dateKey) {
        return selectedUrgency() === 'urgent' ? (urgentSlots[dateKey] || {}) : (routineSlots[dateKey] || {});
    }
    function getSlotCapacities(dateKey) {
        return selectedUrgency() === 'urgent' ? (urgentCapacity[dateKey] || {}) : (routineCapacity[dateKey] || {});
    }
    function isBlocked(dateKey, time) {
        var map = selectedUrgency() === 'urgent' ? urgentBlockedSlots : blockedSlots;
        return !!(map[dateKey] && map[dateKey][time]);
    }
    function matchingWaitlistEntry(dateKey) {
        var pet = document.getElementById('petSelect');
        var type = document.getElementById('apptTypeSelect');
        return residentWaitlistEntries.find(function(entry) {
            return ['waiting', 'notified'].indexOf(entry.status) !== -1
                && entry.preferred_date === dateKey
                && entry.appointment_type === (type ? type.value : '')
                && entry.urgency_level === selectedUrgency()
                && (!pet || !pet.value || String(entry.pet_id) === String(pet.value));
        }) || null;
    }

    function fetchAvailability() {
        var y = viewDate.getFullYear();
        var m = viewDate.getMonth() + 1;
        return fetch('appointment-availability.php?year=' + y + '&month=' + m)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.ok) throw new Error(d.error || 'Could not load appointment availability.');
                routineSlots = d.routineSlots || {};
                urgentSlots = d.urgentSlots || {};
                routineCapacity = d.routineCapacity || {};
                urgentCapacity = d.urgentCapacity || {};
                blockedSlots = d.blocked || {};
                urgentBlockedSlots = d.urgentBlocked || {};
                programEvents = d.programs || {};
                dateOverrides = d.overrides || {};
                maxAppointmentsPerDay = parseInt(d.maxPerDay, 10) || 3;
                maxUrgentPerDay = parseInt(d.maxUrgentPerDay, 10) || 2;
                availabilityError = '';
            })
            .catch(function(error) {
                availabilityError = error.message || 'Could not load appointment availability.';
                console.error('Appointment availability request failed:', error);
                routineSlots = {}; urgentSlots = {}; routineCapacity = {}; urgentCapacity = {};
                blockedSlots = {}; urgentBlockedSlots = {}; programEvents = {}; dateOverrides = {};
            });
    }

    function dayAppointmentCount(dateKey) {
        var counts = getSlotCounts(dateKey);
        return Object.keys(counts).reduce(function(total, time) { return total + (parseInt(counts[time], 10) || 0); }, 0);
    }

    function dayOpenCount(dateKey) {
        var dayLimit = selectedUrgency() === 'urgent' ? maxUrgentPerDay : maxAppointmentsPerDay;
        if (dayAppointmentCount(dateKey) >= dayLimit) return 0;
        var counts = getSlotCounts(dateKey);
        var capacities = getSlotCapacities(dateKey);
        var open = 0;
        BK_SLOTS.forEach(function(s) {
            if (isBlocked(dateKey, s)) return;
            var booked = counts[s] || 0;
            if (booked < (capacities[s] || 0)) open++;
        });
        return open;
    }

    function isClinicClosed(dateKey) {
        return BK_SLOTS.every(function(time) {
            return !!(urgentBlockedSlots[dateKey] && urgentBlockedSlots[dateKey][time]);
        });
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
        if (waitlistStatus) waitlistStatus.textContent = '';
        updateNextState();
    }

    // Next is enabled only when pet, appointment type, date AND time are all chosen
    function updateNextState() {
        if (!bkNextStep) return;
        var petSel = document.getElementById('petSelect');
        var typeSel = document.getElementById('apptTypeSelect');
        var ready = !!(!availabilityError && chosenDate && selectedTime && petSel && petSel.value && typeSel && typeSel.value);
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
        var firstDay = new Date(year, month, 1).getDay(); // Sun=0, matching Schedule Preview
        var daysInMonth = new Date(year, month + 1, 0).getDate();
        var daysInPrevMonth = new Date(year, month, 0).getDate();
        var todayIso = isoDate(today.getFullYear(), today.getMonth(), today.getDate());

        for (var i = 0; i < firstDay; i++) {
            var pad = document.createElement('div');
          pad.className = 'bk-day bk-day-dim';
          pad.textContent = daysInPrevMonth - firstDay + i + 1;
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
            var bookedCount = dayAppointmentCount(key);
            if (isPast) {
                div.classList.add('bk-day-disabled');
                div.disabled = true;
            }
            var dayLimit = selectedUrgency() === 'urgent' ? maxUrgentPerDay : maxAppointmentsPerDay;
            if (!isPast && openCount === 0) {
              div.classList.add('bk-day-full');
              div.title = bookedCount >= dayLimit
                ? 'No ' + selectedUrgency() + ' slots available (' + bookedCount + '/' + dayLimit + ' daily limit)'
                : 'No appointment times available';
            }
            if (!isPast && isClinicClosed(key)) {
              div.classList.add('bk-day-disabled');
              div.disabled = true;
              div.title = 'The clinic is closed on this date';
            }
            if (key === todayIso) div.classList.add('bk-day-today');
            if (selStart !== null && key === selStart) div.classList.add('bk-day-selected');
            var override = dateOverrides[key];
            if (override && Object.keys(override).some(function(time) { return override[time] === true; })) div.classList.add('bk-day-custom');

            if (!div.disabled) {
                div.addEventListener('click', function() {
                    selectDay(this.getAttribute('data-date'));
                });
            }
            bkGrid.appendChild(div);
        }

          var remaining = (firstDay + daysInMonth) % 7;
          if (remaining > 0) {
            for (var nextDay = 1; nextDay <= 7 - remaining; nextDay++) {
              var nextPad = document.createElement('div');
              nextPad.className = 'bk-day bk-day-dim';
              nextPad.textContent = nextDay;
              bkGrid.appendChild(nextPad);
            }
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
        waitlistWrap.hidden = true;
        nearestSlotNotice.hidden = true;
        nearestSlotNotice.textContent = '';
        document.getElementById('bkProgramNotice').hidden = true;
        document.getElementById('bkUrgentCareNotice').hidden = selectedUrgency() !== 'urgent';
        if (selStart === null) {
            bkSlotsHint.textContent = 'Select your preferred appointment date.';
            return;
        }
        if (availabilityError) {
            bkSlotsHint.textContent = availabilityError + ' Please refresh the page and try again.';
            return;
        }
        var dd = new Date(selStart + 'T00:00:00');
        bkSlotsHint.textContent = 'Available times on ' + dd.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' }) + '.';

        var counts = getSlotCounts(selStart);
        var capacities = getSlotCapacities(selStart);
        var anyOpen = false;
        BK_SLOTS.forEach(function(s) {
            var blocked = isBlocked(selStart, s);
            var booked = counts[s] || 0;
            var capacity = capacities[s] || 0;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'bk-slot-btn';
            btn.setAttribute('data-date', selStart);
            btn.setAttribute('data-time', s);
            if (blocked || booked >= capacity) {
                btn.classList.add('bk-slot-unavailable');
                btn.textContent = fmtTime(s);
                btn.disabled = true;
                btn.title = blocked ? 'Clinic closed' : 'No remaining capacity';
            } else {
                anyOpen = true;
                btn.textContent = fmtTime(s) + (capacity > 1 ? ' · ' + (capacity - booked) + ' left' : '');
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
            none.textContent = 'No ' + selectedUrgency() + ' slots are available on this day.';
            bkSlots.appendChild(none);
            var requestId = ++nearestRequestId;
            fetch('includes/check_slot.php?date=' + encodeURIComponent(selStart) + '&time=15%3A00&urgency=' + encodeURIComponent(selectedUrgency()))
                .then(function(response) {
                    if (!response.ok) throw new Error('Could not find the nearest available appointment.');
                    return response.json();
                })
                .then(function(data) {
                    if (requestId !== nearestRequestId || selStart === null) return;
                    if (data.nearest_slot) {
                        var nearest = data.nearest_slot.split(' ');
                        var nearestDate = new Date(nearest[0] + 'T00:00:00');
                        nearestSlotNotice.textContent = 'Nearest available ' + selectedUrgency() + ' appointment: ' + nearestDate.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) + ' at ' + fmtTime(nearest[1]) + '.';
                    } else {
                        nearestSlotNotice.textContent = 'No available appointment found in the next 90 days. ' + (selectedUrgency() === 'urgent' ? 'Call (046) 432-1234 or visit the DCVS office for urgent assistance.' : 'Please join the waitlist or contact the DCVS office.');
                    }
                    nearestSlotNotice.hidden = false;
                })
                .catch(function(error) {
                    if (requestId !== nearestRequestId) return;
                    console.error('Nearest appointment lookup failed:', error);
                    nearestSlotNotice.textContent = 'Could not check the nearest available date. Please try again or contact the DCVS office.';
                    nearestSlotNotice.hidden = false;
                });
        }
        var events = programEvents[selStart] || [];
        if (events.length && typeSelect && typeSelect.value === 'vaccination') {
            var notice = document.getElementById('bkProgramNotice');
            notice.textContent = events.map(function(event) {
                var location = event.venue || event.barangay_names || 'Location to be announced';
                return 'Free Vaccination Drive: ' + event.program_name + ' at ' + location + ', ' + fmtTime(event.start_time) + '–' + fmtTime(event.end_time) + '. Walk-in; no appointment is needed for the drive.';
            }).join(' ');
            notice.hidden = false;
        }
        if (!anyOpen && typeSelect && typeSelect.value && document.getElementById('petSelect').value
            && (selectedUrgency() === 'routine' || ['vaccination', 'checkup', 'deworming'].indexOf(typeSelect.value) !== -1)) {
            waitlistWrap.hidden = false;
            var existingEntry = matchingWaitlistEntry(selStart);
            waitlistButton.disabled = !!existingEntry;
            waitlistButton.textContent = existingEntry ? 'You are on the waitlist' : 'Notify me if a slot opens';
            waitlistStatus.textContent = existingEntry && existingEntry.status === 'notified'
                ? 'A slot opened for you. Please book it before the claim expires.'
                : existingEntry ? 'We will notify you if a matching slot opens.' : '';
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
        var typeLabel = typeSel && typeSel.selectedIndex > -1 ? typeSel.options[typeSel.selectedIndex].textContent : '-';
        document.getElementById('bkDetailType').textContent = typeVal === 'adoption' ? 'Pet Adoption' : (typeVal === 'kapon' ? 'Pet Neutering / Castration' : typeLabel);
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
        document.getElementById('cfUrgency').value = selectedUrgency();
        document.getElementById('bkDetailUrgency').textContent = selectedUrgency() === 'urgent' ? 'Urgent' : 'Routine';
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
        typeSel.addEventListener('change', function() {
            var urgentOption = urgencySelect && urgencySelect.querySelector('option[value="urgent"]');
            var urgentTypeAllowed = ['vaccination', 'checkup', 'deworming'].indexOf(typeSel.value) !== -1;
            if (urgentOption) urgentOption.disabled = !urgentTypeAllowed;
            if (!urgentTypeAllowed && urgencySelect && urgencySelect.value === 'urgent') urgencySelect.value = 'routine';
            clearBooking();
            renderCalendar();
        });
        updateNextState();
    }
    if (urgencySelect) {
        urgencySelect.addEventListener('change', function() {
            clearBooking();
            renderCalendar();
        });
    }
    if (waitlistButton) {
        waitlistButton.addEventListener('click', function() {
            var pet = document.getElementById('petSelect');
            var type = document.getElementById('apptTypeSelect');
            if (!selStart || !pet.value || !type.value) return;
            waitlistButton.disabled = true;
            waitlistStatus.textContent = 'Adding you to the waitlist...';
            var params = new URLSearchParams({
                pet_id: pet.value,
                appointment_type: type.value,
                urgency_level: selectedUrgency(),
                preferred_date: selStart
            });
            fetch('appointment-waitlist.php', { method: 'POST', body: params })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    waitlistStatus.textContent = data.message || 'Could not add you to the waitlist.';
                    waitlistStatus.style.color = data.success ? '#166534' : '#991b1b';
                    if (data.success) {
                        waitlistButton.textContent = 'You are on the waitlist';
                        waitlistButton.disabled = true;
                        var newEntry = {
                            waitlist_id: data.waitlist_id,
                            pet_id: parseInt(pet.value, 10),
                            appointment_type: type.value,
                            urgency_level: selectedUrgency(),
                            preferred_date: selStart,
                            preferred_time: null,
                            status: data.status || 'waiting'
                        };
                        residentWaitlistEntries = residentWaitlistEntries.filter(function(entry) {
                            return !(entry.preferred_date === selStart && entry.appointment_type === type.value
                                && entry.urgency_level === selectedUrgency() && String(entry.pet_id) === String(pet.value)
                                && ['waiting', 'notified'].indexOf(entry.status) !== -1);
                        });
                        residentWaitlistEntries.push(newEntry);
                    }
                })
                .catch(function(error) {
                    console.error('Appointment waitlist request failed:', error);
                    waitlistStatus.textContent = 'Could not add you to the waitlist. Please try again.';
                    waitlistStatus.style.color = '#991b1b';
                })
                .finally(function() {
                    if (!matchingWaitlistEntry(selStart)) waitlistButton.disabled = false;
                });
        });
    }

    function resetSelection() {
        selStart = null;
        clearBooking();
    }

    bkPrev.addEventListener('click', function() {
        viewDate.setMonth(viewDate.getMonth() - 1);
        resetSelection();
        if (bookingPrefill) {
            var prefillDate = new Date(bookingPrefill.preferred_date + 'T00:00:00');
            if (!isNaN(prefillDate.getTime())) {
                selStart = bookingPrefill.preferred_date;
                viewDate = new Date(prefillDate.getFullYear(), prefillDate.getMonth(), 1);
                if (petSel) petSel.value = String(bookingPrefill.pet_id);
                if (typeSel) typeSel.value = bookingPrefill.appointment_type;
                if (urgencySelect) urgencySelect.value = bookingPrefill.urgency_level;
                updatePetProfile();
                fetchAvailability().then(function() {
                    renderCalendar();
                    if (bookingPrefill.preferred_time) {
                        var counts = getSlotCounts(bookingPrefill.preferred_date);
                        var capacities = getSlotCapacities(bookingPrefill.preferred_date);
                        var time = bookingPrefill.preferred_time;
                        if (!isBlocked(bookingPrefill.preferred_date, time) && (counts[time] || 0) < (capacities[time] || 0)) {
                            selectTime(bookingPrefill.preferred_date, time);
                        } else if (nearestSlotNotice) {
                            nearestSlotNotice.textContent = 'The notified time is no longer available. Please choose another open time on this date.';
                            nearestSlotNotice.hidden = false;
                        }
                    }
                    if (typeof switchSection === 'function') switchSection('calendar-booking');
                });
            } else {
                fetchAvailability().then(renderCalendar);
            }
        } else {
            fetchAvailability().then(renderCalendar);
        }
    });
    bkNext.addEventListener('click', function() {
        viewDate.setMonth(viewDate.getMonth() + 1);
        resetSelection();
        fetchAvailability().then(renderCalendar);
    });
    if (bkToday) {
      bkToday.addEventListener('click', function() {
        viewDate = new Date(today.getFullYear(), today.getMonth(), 1);
        resetSelection();
        fetchAvailability().then(renderCalendar);
      });
    }

    fetchAvailability().then(renderCalendar);

    // Reset the booking form so each fresh visit starts clean
    window.resetBookingForm = function() {
        viewDate = new Date(today.getFullYear(), today.getMonth(), 1);
        resetSelection();
        var p = document.getElementById('petSelect');
        if (p) { p.value = ''; }
        var t = document.getElementById('apptTypeSelect');
        if (t) { t.value = ''; }
        if (urgencySelect) urgencySelect.value = 'routine';
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
              return;
            }
            e.preventDefault();
            var submit = this.querySelector('button[type="submit"]');
            var originalText = submit ? submit.textContent : '';
            if (submit) { submit.disabled = true; submit.textContent = 'Submitting...'; }
            var formData = new FormData(this);
            formData.set('ajax', '1');
            var form = this;
            fetch(this.action, { method: 'POST', body: formData })
              .then(function(response) { return response.json(); })
              .then(function(data) {
                if (!data || !data.success) {
                  if (submit) { submit.disabled = false; submit.textContent = originalText; }
                  if (window.portalToast) portalToast('Unable to submit request', (data && data.message) || 'Please try again.');
                  else alert((data && data.message) || 'Unable to submit appointment request.');
                  return;
                }
                try {
                  var petSelect = document.getElementById('cfPet');
                  var petOption = petSelect && petSelect.selectedOptions.length ? petSelect.selectedOptions[0] : null;
                  var petName = petOption ? petOption.textContent.trim().replace(/\s*\(.*\)$/, '') : '-';
                  if (window.APPT && typeof window.APPT.addRequest === 'function') {
                    window.APPT.addRequest(Object.assign({
                      appointment_type: ty,
                      pet_name: petName,
                      pet_photo: petOption ? petOption.getAttribute('data-photo') || '' : '',
                      preferred_date: d,
                      preferred_time: t,
                      status: 'pending',
                      notes: (form.querySelector('[name="notes"]') || {}).value || ''
                    }, data));
                  }
                  var overviewList = document.getElementById('rework-appointments-container');
                  if (overviewList && !overviewList.querySelector('[data-live-appt-id="' + data.appointment_id + '"]')) {
                    var empty = overviewList.querySelector('.rework-empty');
                    if (empty) empty.remove();
                    var item = document.createElement('div');
                    item.className = 'appointment-item-row';
                    item.setAttribute('data-live-appt-id', data.appointment_id);
                    var dateTime = new Date(d + 'T' + t);
                    var timeColumn = document.createElement('div');
                    timeColumn.className = 'appointment-time-col';
                    var timeLabel = document.createElement('span');
                    timeLabel.textContent = dateTime.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });
                    var dot = document.createElement('span');
                    dot.className = 'status-dot-indicator dot-amber';
                    timeColumn.append(timeLabel, dot);
                    var detailColumn = document.createElement('div');
                    detailColumn.className = 'appointment-details-col';
                    var petLabel = document.createElement('span');
                    petLabel.className = 'appointment-pet-name';
                    petLabel.textContent = petName;
                    var serviceLabel = document.createElement('span');
                    serviceLabel.className = 'appointment-reason-meta';
                    var serviceNames = { adoption:'Adoption', kapon:'Kapon', vaccination:'Vaccination', checkup:'Check-up' };
                    serviceLabel.textContent = (serviceNames[ty] || ty) + ' · Awaiting confirmation';
                    detailColumn.append(petLabel, serviceLabel);
                    var badge = document.createElement('span');
                    badge.className = 'status-pill-badge badge-pending';
                    badge.textContent = 'Pending';
                    item.append(timeColumn, detailColumn, badge);
                    overviewList.prepend(item);
                  }
                  if (typeof window.resetBookingForm === 'function') window.resetBookingForm();
                } catch (uiError) {
                  console.error('Appointment was saved, but the page could not refresh its booking details.', uiError);
                }
                closeModal('confirmBookingModal');
                if (window.portalToast) portalToast('Appointment request sent', data.message || 'Your request is pending staff confirmation.');
                if (submit) { submit.disabled = false; submit.textContent = originalText; }
              }, function() {
                if (submit) { submit.disabled = false; submit.textContent = originalText; }
                if (window.portalToast) portalToast('Network error', 'Could not submit appointment request. Please try again.');
                else alert('Network error. Please try again.');
              });
        });
    }
});

// ï¿½"?ï¿½"? Profile Settings Edit Toggle ï¿½"?ï¿½"?
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
        if (err) { err.style.transition = 'opacity 0.5s'; err.style.opacity = '0'; setTimeout(function() { err.style.display = 'none'; }, 500); }
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

var residentWaitlistCancelTarget = null;
var activeResidentWaitlistMenuButton = null;

function toggleResidentWaitlistMenu(event, waitlistId) {
  event.stopPropagation();
  var menu = document.getElementById('residentWaitlistRowMenu');
  var button = event.currentTarget;
  if (activeResidentWaitlistMenuButton === button && menu.classList.contains('open')) {
    closeResidentWaitlistMenu();
    return;
  }

  closeResidentWaitlistMenu();
  activeResidentWaitlistMenuButton = button;
  var status = button.getAttribute('data-waitlist-status');
  var items = [];
  if (status === 'notified') {
    items.push('<a class="resident-waitlist-menu-item" role="menuitem" href="dashboard-resident.php?waitlist_booking=' + waitlistId + '#calendar-booking"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Book now</a>');
  }
  if (status === 'waiting' || status === 'notified') {
    items.push('<button type="button" class="resident-waitlist-menu-item danger" role="menuitem" data-action="cancel" data-waitlist-id="' + waitlistId + '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="m19 6-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>Cancel waitlist</button>');
  }
  if (!items.length) items.push('<span class="resident-waitlist-menu-item" aria-disabled="true">No actions available</span>');
  menu.innerHTML = items.join('');
  menu.classList.add('open');
  button.setAttribute('aria-expanded', 'true');
  var rect = button.getBoundingClientRect();
  var menuWidth = menu.offsetWidth;
  var menuHeight = menu.offsetHeight;
  var left = Math.max(8, Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - 8));
  var top = rect.bottom + menuHeight > window.innerHeight - 8 ? rect.top - menuHeight - 4 : rect.bottom + 4;
  menu.style.left = left + 'px';
  menu.style.top = Math.max(8, top) + 'px';
}

function closeResidentWaitlistMenu() {
  var menu = document.getElementById('residentWaitlistRowMenu');
  if (menu) menu.classList.remove('open');
  if (activeResidentWaitlistMenuButton) activeResidentWaitlistMenuButton.setAttribute('aria-expanded', 'false');
  activeResidentWaitlistMenuButton = null;
}

function confirmResidentWaitlistCancel(waitlistId, button) {
  residentWaitlistCancelTarget = {id: waitlistId, button: button};
  var dialog = document.getElementById('residentWaitlistConfirm');
  if (!dialog) return;
  dialog.classList.add('open');
  document.getElementById('residentWaitlistConfirmAction').focus();
}

function closeResidentWaitlistConfirm() {
  var dialog = document.getElementById('residentWaitlistConfirm');
  if (dialog) dialog.classList.remove('open');
  residentWaitlistCancelTarget = null;
}

function cancelWaitlistEntry(waitlistId, button) {
  button.disabled = true;
  fetch('appointment-waitlist-action.php', {
    method: 'POST',
    body: new URLSearchParams({waitlist_id: String(waitlistId), action: 'cancel'})
  }).then(function(response) {
    return response.json().then(function(result) {
      if (!response.ok || !result.success) throw new Error(result.message || 'Could not cancel this waitlist entry.');
      window.location.href = 'dashboard-resident.php#my-waitlist';
    });
  }).catch(function(error) {
    closeResidentWaitlistConfirm();
    window.alert(error.message);
    button.disabled = false;
  });
}

function initResidentWaitlistTable() {
  var section = document.getElementById('my-waitlist');
  if (!section) return;

  var tableRows = Array.prototype.slice.call(section.querySelectorAll('#residentWaitlistRows tr[data-waitlist-id]'));
  var search = document.getElementById('residentWaitlistSearch');
  var dateInput = document.getElementById('residentWaitlistDate');
  var dateButton = document.getElementById('residentWaitlistDateButton');
  var clearDate = document.getElementById('residentWaitlistClearDate');
  var serviceFilter = document.getElementById('residentWaitlistService');
  var statusFilter = document.getElementById('residentWaitlistStatus');
  var perPageSelect = document.getElementById('residentWaitlistPerPage');
  var pageLabel = document.getElementById('residentWaitlistPageLabel');
  var emptyRow = document.getElementById('residentWaitlistEmpty');
  var page = 1;

  function filteredRows() {
    var query = search.value.trim().toLowerCase();
    return tableRows.filter(function(row) {
    var searchText = row.dataset.pet + ' ' + row.dataset.service;
    return (!query || searchText.indexOf(query) !== -1)
      && (!dateInput.value || row.dataset.date === dateInput.value)
      && (!serviceFilter.value || row.dataset.service === serviceFilter.value)
      && (!statusFilter.value || row.dataset.status === statusFilter.value);
    });
  }

  function render() {
    var matchingRows = filteredRows();
    var pageSize = parseInt(perPageSelect.value, 10) || 10;
    var pageCount = Math.max(1, Math.ceil(matchingRows.length / pageSize));
    page = Math.min(page, pageCount);
    var start = (page - 1) * pageSize;
    var end = start + pageSize;

    tableRows.forEach(function(row) { row.style.display = 'none'; });
    matchingRows.slice(start, end).forEach(function(row) { row.style.display = ''; });

    var noRows = matchingRows.length === 0;
    emptyRow.style.display = noRows ? '' : 'none';
    var emptyCell = emptyRow.querySelector('td');
    emptyCell.classList.toggle('visible', noRows);
    emptyCell.textContent = tableRows.length && noRows
    ? 'No waitlist entries match these filters.'
    : 'You have no waitlist entries.';
    pageLabel.textContent = 'Page ' + page + ' of ' + pageCount;
    document.getElementById('residentWaitlistFirst').disabled = page <= 1;
    document.getElementById('residentWaitlistPrev').disabled = page <= 1;
    document.getElementById('residentWaitlistNext').disabled = page >= pageCount;
    document.getElementById('residentWaitlistLast').disabled = page >= pageCount;
  }

  function resetAndRender() {
    page = 1;
    render();
  }

  search.addEventListener('input', resetAndRender);
  dateInput.addEventListener('change', function() {
    var selected = dateInput.value;
    dateButton.classList.toggle('has-date', !!selected);
    clearDate.hidden = !selected;
    resetAndRender();
  });
  dateButton.addEventListener('click', function() {
    if (typeof dateInput.showPicker === 'function') dateInput.showPicker();
    else dateInput.click();
  });
  clearDate.addEventListener('click', function() {
    dateInput.value = '';
    dateInput.dispatchEvent(new Event('change'));
  });
  serviceFilter.addEventListener('change', resetAndRender);
  statusFilter.addEventListener('change', resetAndRender);
  perPageSelect.addEventListener('change', resetAndRender);
  document.getElementById('residentWaitlistFirst').addEventListener('click', function() { page = 1; render(); });
  document.getElementById('residentWaitlistPrev').addEventListener('click', function() { page--; render(); });
  document.getElementById('residentWaitlistNext').addEventListener('click', function() { page++; render(); });
  document.getElementById('residentWaitlistLast').addEventListener('click', function() {
    page = Math.ceil(filteredRows().length / (parseInt(perPageSelect.value, 10) || 10));
    render();
  });

  document.getElementById('residentWaitlistCancelConfirm').addEventListener('click', closeResidentWaitlistConfirm);
  document.getElementById('residentWaitlistRowMenu').addEventListener('click', function(event) {
    var actionItem = event.target.closest('[data-action="cancel"][data-waitlist-id]');
    if (!actionItem) return;
    event.preventDefault();
    var waitlistId = parseInt(actionItem.getAttribute('data-waitlist-id'), 10);
    var trigger = activeResidentWaitlistMenuButton;
    closeResidentWaitlistMenu();
    if (trigger && waitlistId) confirmResidentWaitlistCancel(waitlistId, trigger);
  });
  document.getElementById('residentWaitlistConfirmAction').addEventListener('click', function() {
    if (!residentWaitlistCancelTarget) return;
    var target = residentWaitlistCancelTarget;
    closeResidentWaitlistConfirm();
    cancelWaitlistEntry(target.id, target.button);
  });
  document.getElementById('residentWaitlistConfirm').addEventListener('click', function(event) {
    if (event.target === this) closeResidentWaitlistConfirm();
  });
  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
      closeResidentWaitlistConfirm();
      closeResidentWaitlistMenu();
    }
  });
  document.addEventListener('click', function(event) {
    if (!event.target.closest('#residentWaitlistRowMenu') && !event.target.closest('.resident-waitlist-row-actions')) closeResidentWaitlistMenu();
  });
  window.addEventListener('resize', closeResidentWaitlistMenu);
  window.addEventListener('scroll', closeResidentWaitlistMenu, true);

  render();
}

initResidentWaitlistTable();

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
  if (data.appointments && data.appointments.length && window.APPT && typeof window.APPT.updateFromRealtime === 'function') {
    data.appointments.forEach(function(appt) { window.APPT.updateFromRealtime(appt); });
  }
    // Reports rows
    if (data.reports && data.reports.length) {
        var rtable = document.querySelector('#reportTable');
        if (rtable) {
            var rtbody = rtable.querySelector('tbody');
            var rColors = {
              'pending':   ['#FEF3C7', '#92400E', '#FDE68A'],
              'validated': ['#dcebe0', '#045e1f', '#bbf7d0'],
              'confirmed': ['#EFF6FF', '#1E40AF', '#BFDBFE'],
              'scheduled': ['#EFF6FF', '#1E40AF', '#BFDBFE'],
              'resolved':  ['#0E8131', '#ffffff', '#0E8131'],
              'rejected':  ['#FEE2E2', '#991B1B', '#FECACA']
            };
            var rLabels = {
              'pending': 'Pending Barangay Review', 'validated': 'Pending DCVS Review', 'confirmed': 'In Progress',
              'scheduled': 'In Progress', 'resolved': 'Resolved', 'rejected': 'Closed'
            };
            var rKnownTypes = {'stray':'Stray Animal','rabies':'Rabies Case','injured':'Injured Animal','dead':'Dead Animal','other':'Other'};
            data.reports.forEach(function(rpt) {
              var existing = rtable.querySelector('tr[data-id="' + rpt.report_id + '"]');
              if (existing) {
                var existingStatus = existing.querySelector('.appt-status-pill');
                var existingColor = rColors[rpt.status] || ['#f1f5f9', '#64748b', '#e4e4e7'];
                var existingLabel = rLabels[rpt.status] || (rpt.status ? rpt.status.charAt(0).toUpperCase() + rpt.status.slice(1) : '');
                existing.setAttribute('data-status', rpt.status);
                if (existingStatus) {
                  existingStatus.classList.toggle('report-status-resolved', rpt.status === 'resolved');
                  existingStatus.textContent = existingLabel;
                  existingStatus.style.background = existingColor[0];
                  existingStatus.style.color = existingColor[1];
                  existingStatus.style.borderColor = existingColor[2] || '#e4e4e7';
                }
                var existingCells = existing.querySelectorAll('td');
                if (existingCells[6]) existingCells[6].textContent = rpt.status === 'resolved' && rpt.updated_at
                  ? new Date(rpt.updated_at.replace(' ', 'T')).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'})
                  : '-';
                var reportItem = (window.__reportList || []).find(function(item) { return Number(item.id) === Number(rpt.report_id); });
                if (reportItem) {
                  reportItem.status = rpt.status;
                  reportItem.statusLabel = existingLabel;
                  var reportStatusClasses = { pending:'st-pending', validated:'st-awaiting', confirmed:'st-review', scheduled:'st-review', resolved:'st-resolved', rejected:'st-rejected' };
                  reportItem.statusClass = reportStatusClasses[rpt.status] || 'st-pending';
                  if (rpt.status === 'resolved') reportItem.resolution = 'This report has been resolved by DCVS.';
                }
                if (window.RPT && typeof window.RPT.refresh === 'function') window.RPT.refresh();
                if (window.RPT && typeof window.RPT.refreshSummary === 'function') window.RPT.refreshSummary();
                if (Number(window.__currentReportId) === Number(rpt.report_id) && window.RPT && typeof window.RPT.view === 'function') window.RPT.view(rpt.report_id);
                return;
              }
                var ref = rpt.reference_id || '#RPT-' + String(rpt.report_id).padStart(4, '0');
                var rc = rColors[rpt.status] || ['#f1f5f9', '#64748b', '#e4e4e7'];
                var rlab = rLabels[rpt.status] || (rpt.status ? rpt.status.charAt(0).toUpperCase() + rpt.status.slice(1) : '');
                var typeLabel = (rKnownTypes[(rpt.report_type || '').toLowerCase()] || rpt.report_type || 'Report') + ' Report';
                var catLabel = (rKnownTypes[(rpt.report_type || '').toLowerCase()] || rpt.report_type || 'Report');
                var incidentAt = rpt.incident_at || rpt.created_at || '';
                var dt = new Date(incidentAt.replace(' ', 'T'));
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
                rtr.setAttribute('data-date', String(incidentAt ? Math.floor(new Date(incidentAt.replace(' ', 'T')).getTime() / 1000) : '0'));
                rtr.setAttribute('data-resolved', resolvedRaw);
                rtr.setAttribute('onclick', 'RPT.view(' + rpt.report_id + ')');
                rtr.innerHTML =
                    '<td class="sd-td"><div class="client-name">' + ref + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal report-type-value">' + typeLabel + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal report-location-value">' + (rpt.location_address || '-') + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + ds + '</div></td>' +
                    '<td class="sd-td"><span class="appt-status-pill' + (rpt.status === 'resolved' ? ' report-status-resolved' : '') + '" style="background:' + rc[0] + ';color:' + rc[1] + ';border-color:' + (rc[2] || '#e4e4e7') + ';">' + rlab + '</span></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal report-type-value">' + catLabel + '</div></td>' +
                    '<td class="sd-td"><div class="contact-main contact-normal">' + resolvedDate + '</div></td>' +
                    '<td class="sd-td sd-td-action" onclick="event.stopPropagation()"><button class="sd-row-actions" title="More options" onclick="event.stopPropagation();rptRowActions(event,' + rpt.report_id + ')"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>';
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
                    '<td class="sd-td sd-td-action" onclick="event.stopPropagation()"><button class="sd-row-actions" title="More options" onclick="event.stopPropagation();petRowActions(event,' + pet.pet_id + ')"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>';
                tbody.appendChild(tr);
                if (window.PET && typeof window.PET.refresh === 'function') window.PET.refresh();
            });
        }
    }
});
</script>
<script>
/* ========== RESIDENT DASHBOARD REWORK - INTERACTIVE JS ========== */
function reworkToggleDropdown(e) {
  e.stopPropagation();
  var menu = document.getElementById('rework-action-menu');
  if (menu) menu.classList.toggle('show');
}
function reworkCloseDropdown() {
  var menu = document.getElementById('rework-action-menu');
  if (menu) menu.classList.remove('show');
}
document.addEventListener('click', function() { reworkCloseDropdown(); });

function reworkOpenModal(id) {
  if (typeof openModal === 'function') {
    openModal(id);
  } else {
    var el = document.getElementById(id);
    if (el) { el.classList.add('active'); el.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
  }
}

function reworkSelectVisit(el, data) {
  document.querySelectorAll('.appointment-item-row').forEach(function(row) { row.classList.remove('active-visit'); });
  if (el) el.classList.add('active-visit');
  var dayEl = document.getElementById('rework-visit-day');
  var monthEl = document.getElementById('rework-visit-month');
  var statusEl = document.getElementById('rework-visit-status');
  var titleEl = document.getElementById('rework-visit-title');
  var petEl = document.getElementById('rework-visit-pet');
  var timeEl = document.getElementById('rework-visit-time');
  var instrEl = document.getElementById('rework-visit-instruction');
  if (dayEl && data.day) dayEl.textContent = data.day;
  if (monthEl && data.month) monthEl.textContent = data.month;
  if (titleEl) titleEl.textContent = data.service;
  if (petEl) petEl.textContent = 'For ' + data.pet;
  if (timeEl) timeEl.textContent = data.time;
  if (instrEl) instrEl.textContent = data.nextStep || 'Bring your pet records';
  if (statusEl) {
    statusEl.textContent = data.status;
    statusEl.className = 'status-pill-badge ' + (data.status.toLowerCase() === 'confirmed' ? 'badge-confirmed' : 'badge-pending');
  }
  if (data && data.apptId) {
    ['rework-reschedule-btn', 'rework-view-btn'].forEach(function(btnId) {
      var el = document.getElementById(btnId);
      if (el) el.setAttribute('data-appt-id', data.apptId);
    });
    var rsBtn = document.getElementById('rework-reschedule-btn');
    if (rsBtn) {
      rsBtn.setAttribute('data-appt-type', data.type || '');
      rsBtn.setAttribute('data-appt-pet', data.petId != null ? data.petId : '');
      rsBtn.setAttribute('data-appt-date', data.date || '');
      rsBtn.setAttribute('data-appt-time', data.timeRaw || '');
      rsBtn.setAttribute('data-appt-notes', data.notes || '');
    }
  }
}

function reworkViewAppointment() {
  var btn = document.getElementById('rework-view-btn');
  var id = btn ? parseInt(btn.getAttribute('data-appt-id'), 10) : 0;
  if (typeof switchSection === 'function') switchSection('appointments');
  if (id && window.APPT && typeof APPT.view === 'function') APPT.view(id);
}

function reworkRescheduleVisit() {
  var btn = document.getElementById('rework-reschedule-btn');
  if (!btn) return;
  var d = btn.dataset;
  if (typeof window.openAppointmentForm === 'function') {
    window.openAppointmentForm({
      id: d.apptId || '',
      service: d.apptType || '',
      petId: d.apptPet || '',
      dateRaw: d.apptDate || '',
      timeRaw: d.apptTime || '',
      notes: d.apptNotes || ''
    }, 'reschedule');
    return;
  }
  reworkOpenModal('editAppointmentModal');
}

function reworkUpdateGreetingDate() {
  var el = document.getElementById('rework-live-date');
  if (el) {
    var now = new Date();
    var days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    el.textContent = days[now.getDay()] + ', ' + months[now.getMonth()] + ' ' + now.getDate() + ', ' + now.getFullYear();
  }
}
reworkUpdateGreetingDate();
setInterval(reworkUpdateGreetingDate, 60000);
</script>
</body>
</html>
