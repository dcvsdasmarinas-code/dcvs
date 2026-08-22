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
$stmt = $conn->prepare("SELECT pet_id, reference_id, pet_name, pet_type, breed, age, weight, gender, color, vaccination_status, photo_url, created_at, EXISTS(SELECT 1 FROM adoption_listings WHERE pet_id = p.pet_id) AS from_adoption FROM pets p WHERE owner_id = ? AND archived_at IS NULL ORDER BY pet_id ASC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $pets[] = $row;
}
$stmt->close();

// Fetch archived pets
$archivedPets = [];
$stmt = $conn->prepare("SELECT pet_id, reference_id, pet_name, pet_type, breed, age, weight, gender, color, vaccination_status, archived_at, photo_url FROM pets WHERE owner_id = ? AND archived_at IS NOT NULL ORDER BY pet_id ASC");
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
$stmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.preferred_date, a.preferred_time, a.status, a.pet_id, a.notes, a.created_at, COALESCE(p.pet_name, '—') AS pet_name FROM appointments a LEFT JOIN pets p ON a.pet_id = p.pet_id WHERE a.user_id = ? AND a.archived_at IS NULL ORDER BY a.appointment_id ASC");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $appointments[] = $row;
}
$stmt->close();

// Fetch archived appointments
$archivedAppointments = [];
$stmt = $conn->prepare("SELECT a.appointment_id, a.reference_id, a.appointment_type, a.preferred_date, a.preferred_time, a.status, a.archived_at, COALESCE(p.pet_name, '—') AS pet_name FROM appointments a LEFT JOIN pets p ON a.pet_id = p.pet_id WHERE a.user_id = ? AND a.archived_at IS NOT NULL ORDER BY a.appointment_id ASC");
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
    SELECT al.*, p.pet_name, p.pet_type, p.breed, p.age, p.gender, p.color, p.photo_url, p.reference_id AS pet_ref_id,
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Resident Dashboard — Dasmari&ntilde;as City Veterinary Services Animal Welfare System" />
  <title><?php echo htmlspecialchars($pageTitle); ?> — Resident | DCVS</title>

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
    .page-title { font-size:1.35rem; font-weight:800; color:#0f172a; margin:0; font-family:'Montserrat',sans-serif; }
    .page-subtitle { font-size:0.88rem; color:#64748b; margin:4px 0 0; }
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
    .archive-section { margin-top:2rem; padding-top:1.5rem; border-top:2px dashed #e2e8f0; }
    .archive-section h2 { font-size:1.1rem; font-weight:700; color:#0f172a; margin:0 0 1rem 0; }

    .compact-list { padding: 0.5rem 1.5rem 1.25rem; display: flex; flex-direction: column; gap: 0.25rem; }
    .compact-item { display:flex; align-items:center; gap:0.85rem; padding:0.75rem 0.85rem; border-bottom:1px solid #f1f5f9; border-radius:8px; }
    .compact-item:last-child { border-bottom:none; }
    .compact-item:hover { background:#fafbfc; }
    .compact-id { font-family:monospace; font-size:0.72rem; color:#94a3b8; }
    .compact-title { font-size:0.85rem; font-weight:600; color:#0f172a; flex:1; }
    .compact-main { flex:1; }
    .filter-bar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:1rem; }
    .search-box { display:inline-flex; align-items:center; background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:8px; padding:0 12px; transition:border-color 0.15s,box-shadow 0.15s; }
    .search-box:focus-within { border-color:var(--green); box-shadow:0 0 0 3px rgba(14,129,49,0.1); }
    .search-box i { color:#94a3b8; font-size:0.85rem; }
    .search-box input { border:none; background:transparent; padding:8px 10px; font-size:0.85rem; color:#1e293b; outline:none; width:100%; min-width:180px; }
    .search-box input::placeholder { color:#94a3b8; }
    .filter-select { padding:8px 14px; border-radius:8px; border:1.5px solid #e2e8f0; font-size:0.82rem; background:#fff; color:#334155; cursor:pointer; transition:border-color 0.15s; }
    .filter-select:focus { border-color:var(--green); outline:none; box-shadow:0 0 0 3px rgba(14,129,49,0.1); }
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

    /* ── Adoption Listings (premium CRM record design) ── */
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
    .adopt-status-badge { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; padding:5px 11px; border-radius:999px; letter-spacing:.02em; }
    .adopt-status-badge.pending { background:#FFF7ED; color:#C2540B; }
    .adopt-status-badge.available { background:#ECFDF5; color:#0E8131; }
    .adopt-status-badge.adopted { background:#F3F4F6; color:#6B7280; }
    .adopt-status-badge.cancelled { background:#FEF2F2; color:#B91C1C; }
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
/* ── Profile Settings (new layout) ── */
.profile-settings-wrapper * { box-sizing:border-box; }
.profile-settings-wrapper { max-width:680px; margin:0 auto; }
.profile-settings-wrapper .ps-card { background:#fff; border:1px solid #e6e8eb; border-radius:14px; padding:22px 24px; margin-bottom:20px; box-shadow:0 1px 2px rgba(0,0,0,0.02); }
.profile-settings-wrapper .ps-profile-card { display:flex; align-items:center; justify-content:space-between; }
.profile-settings-wrapper .ps-profile-left { display:flex; align-items:center; gap:16px; }
.profile-settings-wrapper .ps-avatar { width:56px; height:56px; border-radius:50%; overflow:hidden; flex-shrink:0; background:#d9d9d9; cursor:pointer; }
.profile-settings-wrapper .ps-avatar img { width:100%; height:100%; object-fit:cover; }
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
.profile-settings-wrapper .ps-actions-row { display:flex; justify-content:flex-end; gap:10px; margin-top:16px; }
.profile-settings-wrapper .ps-btn { padding:9px 18px; font-size:13px; font-weight:600; border-radius:8px; cursor:pointer; border:1px solid transparent; }
.profile-settings-wrapper .ps-btn-primary { background:#000; color:#fff; border-color:#000; }
.profile-settings-wrapper .ps-btn-primary:hover { background:#1a1a1a; }
.profile-settings-wrapper .ps-btn-primary:disabled { background:#c7c9d9; border-color:#c7c9d9; cursor:not-allowed; }
.profile-settings-wrapper .ps-btn-secondary { background:#fff; color:#1f2328; border-color:#d7dae0; }
.profile-settings-wrapper .ps-btn-secondary:hover { background:#f5f6f8; }
.profile-settings-wrapper .ps-footer-actions { display:flex; justify-content:flex-start; gap:10px; margin-top:4px; }
.profile-settings-wrapper .ps-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(20px); background:#1f2328; color:#fff; padding:10px 18px; border-radius:8px; font-size:13px; opacity:0; pointer-events:none; transition:opacity .2s,transform .2s; z-index:9999; }
.profile-settings-wrapper .ps-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }

/* ── Dashboard Layout (corrected) ── */
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
/* Booking calendar (reui style — shadcn/ui look) */
.bk-card{border:1px solid #e2e8f0;border-radius:12px;background:#fff;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,0.04);}
.bk-card-header{display:flex;align-items:center;border-bottom:1px solid #e2e8f0;padding:12px 16px;}
.bk-card-header h3{margin:0;font-size:0.95rem;font-weight:600;color:#0f172a;}
.bk-content{position:relative;padding:0;}
.bk-content-main{padding:16px;}
.bk-cal-nav{display:flex;align-items:center;justify-content:space-between;padding:0 8px;margin-bottom:10px;}
.bk-cal-title{font-size:0.85rem;font-weight:600;color:#0f172a;}
.bk-cal-btn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;border:1px solid transparent;background:transparent;color:#64748b;cursor:pointer;transition:background 0.15s;}
.bk-weekdays{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;}
.bk-weekdays span{text-align:center;font-size:0.7rem;font-weight:500;color:#94a3b8;padding:4px 0;}
.bk-days{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;}
.bk-day{display:flex;align-items:center;justify-content:center;height:34px;font-size:0.8rem;color:#0f172a;border-radius:6px;border:1px solid transparent;background:transparent;cursor:pointer;transition:background 0.15s;width:100%;}
.bk-day.bk-day-today{box-shadow:inset 0 0 0 1px #0f172a;}
.bk-day.bk-day-selected{background:#0f172a;color:#fff;}
.bk-day.bk-day-disabled{color:#cbd5e1;cursor:not-allowed;opacity:0.5;}
.bk-slot-panel{display:flex;flex-direction:column;border-top:1px solid #e2e8f0;}
.bk-slot-scroll{display:flex;flex-direction:column;gap:6px;padding:12px;overflow-y:auto;max-height:240px;}
.bk-slot-btn{width:100%;text-align:left;padding:8px 12px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;font-size:0.82rem;color:#0f172a;cursor:pointer;transition:background 0.15s;display:flex;align-items:center;justify-content:space-between;gap:6px;}
.bk-slot-btn.bk-slot-selected{background:#0f172a;color:#fff;border-color:#0f172a;}
.bk-slot-btn:disabled{color:#94a3b8;cursor:not-allowed;background:#f8fafc;border-color:#e2e8f0;}
.bk-slot-count{font-size:0.7rem;font-weight:500;opacity:0.7;}
.bk-footer{display:flex;flex-direction:column;gap:12px;align-items:flex-start;border-top:1px solid #e2e8f0;padding:12px 16px;}
.bk-footer-confirm{display:flex;align-items:center;gap:8px;font-size:0.85rem;color:#334155;max-width:100%;}
.bk-footer-confirm svg{width:16px;height:16px;color:#0E8131;flex-shrink:0;}
.bk-footer-confirm strong{color:#0f172a;font-weight:600;}
.bk-confirm-btn{margin-left:0;width:auto;padding:12px 24px;border-radius:9px;border:none;background:var(--green,#0E8131);font-size:0.85rem;font-weight:600;color:#fff;cursor:pointer;transition:background 0.15s;}
.bk-confirm-btn:hover:not(:disabled){background:#0a6b28;}
.bk-confirm-btn:disabled{opacity:0.5;cursor:not-allowed;background:#94a3b8;}
@media (min-width:768px){
  .bk-slot-panel{position:absolute;top:0;right:0;bottom:0;width:190px;border-top:none;border-left:1px solid #e2e8f0;}
  .bk-content{padding-right:190px;}
  .bk-footer{flex-direction:row;align-items:center;}
  .bk-slot-scroll{max-height:none;height:100%;}
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


/* ── Overflow guard: prevent horizontal scroll on any screen size ── */
.main-content,#mainContent,.dashboard-grid,.dash-main,.dash-sidebar{max-width:100%;overflow-x:hidden;box-sizing:border-box;}
.dashboard-grid>*,.dash-main>*,.dash-sidebar>*,.top-row>*{min-width:0;}

/* ── Responsive breakpoints ── */
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
</head>
<body class="dashboard" data-realtime-page="resident">

<?php include 'includes/resident-sidebar.php'; ?>

<!-- ══════════════════════════════════════════
     MAIN CONTENT
══════════════════════════════════════════ -->
<main class="main-content" id="mainContent">

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

  <!-- ────────── OVERVIEW / DASHBOARD ────────── -->
  <section id="overview" class="content-section active">

    <?php if (isset($_SESSION['login_notice'])): ?>
      <div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;color:#1e40af;font-size:0.9rem;">
        <i class="fas fa-info-circle"></i>
        <span><?php echo htmlspecialchars($_SESSION['login_notice']); unset($_SESSION['login_notice']); ?></span>
        <button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;color:#1e40af;cursor:pointer;font-size:1.1rem;">&times;</button>
      </div>
    <?php endif; ?>

    <!-- Welcome Banner -->
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
      <div class="action-card" onclick="openModal('submitReportModal')">
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
      <div class="action-card" onclick="openModal('appointmentModal')">
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
      <div class="action-card" onclick="document.getElementById('navAdoption').click();return false;">
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
              <a href="#" onclick="document.getElementById('navReports').click();return false;">View all reports</a>
              <a href="#" class="action" onclick="openModal('submitReportModal');return false;">
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
              $apptData[$d][] = ['pet' => htmlspecialchars($apt['pet_name'] ?? '—'), 'service' => ucfirst($apt['appointment_type']), 'time' => $apt['preferred_time'], 'status' => $apt['status']];
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
                          <h4><?php echo htmlspecialchars($apt['pet_name'] ?? '—'); ?> (<?php echo ucfirst($petTypeIcon); ?>)</h4>
                          <p><?php echo ucfirst($apt['appointment_type']); ?></p>
                        </div>
                      </div>
                      <div class="appointment-status">
                        <?php
                        $apptStatusStr = $apt['status'];
                        $statusPillColor = '#0E8131';
                        $statusPillBg = '#ECFDF5';
                        if ($apptStatusStr === 'pending') { $statusPillColor = '#C2540B'; $statusPillBg = '#FFF7ED'; }
                        elseif ($apptStatusStr === 'scheduled') { $statusPillColor = '#0E8131'; $statusPillBg = '#ECFDF5'; }
                        elseif (in_array($apptStatusStr, ['completed','resolved'])) { $statusPillColor = '#10B981'; $statusPillBg = '#ECFDF5'; }
                        elseif (in_array($apptStatusStr, ['cancelled','rejected'])) { $statusPillColor = '#B91C1C'; $statusPillBg = '#FEF2F2'; }
                        $statusPillText = ucfirst(str_replace('_',' ',$apptStatusStr));
                        ?>
                          <span class="appt-status-pill" style="color:<?php echo $statusPillColor; ?>;background:<?php echo $statusPillBg; ?>;"><?php echo $statusPillText; ?></span>
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
              <a href="#" onclick="document.getElementById('navAppointments').click();return false;">View all appointments</a>
              <a href="#" class="action" onclick="openModal('appointmentModal');return false;">
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
            $activities[] = ['title' => htmlspecialchars($appt['pet_name'] ?? '—') . ' — ' . ucfirst($appt['appointment_type']), 'subtitle' => 'Scheduled appointment', 'date' => $appt['created_at'], 'category' => 'Appointments', 'status' => ucfirst($appt['status'])];
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
    // Hide ALL rows first, then show current page — prevents visual overlap during reflow
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
                $subject = preg_replace('/^Message from Staff\s*[—–-]\s*/', '', $n['title'] ?? '');
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
                // Control points — tension 0.25 for a gentle curve
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

  </section>

  <!-- ────────── MY PETS ────────── -->
  <section id="my-pets" class="content-section">
    <div class="page-header">
      <div>
        <h1 class="page-title">My Pets</h1>
        <p class="page-subtitle">Manage all your registered pet profiles</p>
      </div>
      <button class="btn-primary" onclick="openModal('addPetModal')">
        <i class="fas fa-plus"></i> Register a Pet
      </button>
    </div>

    <div class="table-card">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;padding-bottom:14px;border-bottom:1px solid #f1f5f9;margin-bottom:14px;">
        <span data-count="pets" style="background:#f1f5f9;color:#64748b;font-size:0.78rem;font-weight:600;padding:3px 9px;border-radius:999px;"><?php echo count($pets); ?> total</span>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <div style="position:relative;">
            <i class="fas fa-search" style="position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:0.85rem;"></i>
            <input type="text" id="petSearch" placeholder="Search by name, breed..." style="padding:9px 14px 9px 38px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:0.88rem;outline:none;width:220px;background:#f8fafc;color:#334155;transition:all 0.2s;" onfocus="this.style.borderColor='#10b981';this.style.boxShadow='0 0 0 3px rgba(16,185,129,0.1)'" onblur="this.style.borderColor='#e2e8f0';this.style.boxShadow='none'" onkeyup="filterPets()">
          </div>
          <select id="petTypeFilter" onchange="filterPets()" style="padding:9px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:0.88rem;outline:none;background:#f8fafc;color:#334155;cursor:pointer;">
            <option value="">All Types</option>
            <option value="dog">Dog</option>
            <option value="cat">Cat</option>
            <option value="other">Other</option>
          </select>
          <select id="petStatusFilter" onchange="filterPets()" style="padding:9px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:0.88rem;outline:none;background:#f8fafc;color:#334155;cursor:pointer;">
            <option value="">All Status</option>
            <option value="Fully vaccinated">Vaccinated</option>
            <option value="Not vaccinated">Not Vaccinated</option>
            <option value="Partially vaccinated">Partially Vaccinated</option>
          </select>
        </div>
      </div>
      <?php if (empty($pets)): ?>
        <table class="data-table"><tbody><tr><td colspan="9" style="text-align:center;color:#94a3b8;padding:2rem;"><i class="fas fa-search" style="font-size:1.5rem;display:block;margin-bottom:8px;opacity:0.4;"></i>No pets registered yet.</td></tr></tbody></table>
      <?php else: ?>
        <table class="data-table" id="petsTable">
          <thead>
            <tr>
              <th style="width:50px;">No.</th>
              <th style="font-weight:700;">Pet ID</th>
              <th>Name</th>
              <th>Species</th>
              <th>Breed</th>
              <th>Category</th>
              <th>Vaccination Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php $pNum = 1; foreach ($pets as $pet): ?>
              <tr>
                <td style="color:#94a3b8;font-size:0.85rem;font-weight:600;"><?php echo $pNum++; ?></td>
                <td style="font-family:monospace; color:#475569; font-weight:700;"><?php echo htmlspecialchars($pet['reference_id'] ?? '#PET-'.str_pad($pet['pet_id'], 4, '0', STR_PAD_LEFT)); ?></td>
                <td><strong><?php echo htmlspecialchars($pet['pet_name']); ?></strong></td>
                <td><?php echo ucfirst($pet['pet_type']); ?></td>
                <td><?php echo htmlspecialchars($pet['breed'] ?? '-'); ?></td>
                <td><span class="badge-category"><?php echo $pet['from_adoption'] ? 'Adopted' : 'Owned'; ?></span></td>
                <td>
                  <strong style="font-size:0.82rem;"><?php echo htmlspecialchars($pet['vaccination_status']); ?></strong>
                </td>
                <td style="white-space:nowrap;">
                  <a href="pet-profile.php?id=<?php echo $pet['pet_id']; ?>&ref=resident" class="btn-sm btn-secondary" style="text-decoration:none;display:inline-flex;align-items:center;gap:4px;" title="View profile"><i class="fas fa-file-alt"></i></a>
                  <?php if (!$pet['from_adoption']): ?>
                    <button class="btn-sm btn-secondary" onclick="editPet(this)" title="Edit"
                      data-pet-id="<?php echo $pet['pet_id']; ?>"
                      data-pet-name="<?php echo htmlspecialchars($pet['pet_name']); ?>"
                      data-pet-species="<?php echo $pet['pet_type']; ?>"
                      data-pet-breed="<?php echo htmlspecialchars($pet['breed'] ?? ''); ?>"
                      data-pet-gender="<?php echo $pet['gender']; ?>"
                      data-pet-age="<?php echo $pet['age']; ?>"
                      data-pet-weight="<?php echo $pet['weight'] ?? ''; ?>"
                      data-pet-color="<?php echo htmlspecialchars($pet['color'] ?? ''); ?>"
                      data-pet-vaccinated="<?php echo $pet['vaccination_status'] === 'Fully vaccinated' ? 'yes' : ($pet['vaccination_status'] === 'Partially vaccinated' ? 'partial' : 'no'); ?>"
                      data-pet-photo="<?php echo htmlspecialchars($pet['photo_url'] ?? ''); ?>"><i class="fas fa-edit"></i></button>
                  <?php endif; ?>
                  <button class="btn-sm btn-secondary" onclick="archivePet(<?php echo $pet['pet_id']; ?>)" title="Archive"><i class="fas fa-archive"></i></button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </section>

  <!-- ────────── MY REPORTS ────────── -->
  <section id="my-reports" class="content-section">
    <div class="page-header">
      <div>
        <h1 class="page-title">My Reports</h1>
        <p class="page-subtitle">Track your submitted stray and rabies case reports</p>
      </div>
      <button class="btn-primary" onclick="openModal('submitReportModal')">
        <i class="fas fa-plus"></i> Submit a Report
      </button>
    </div>

    

    <div class="table-card">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;padding-bottom:14px;border-bottom:1px solid #f1f5f9;margin-bottom:14px;">
        <span data-count="reports" style="background:#f1f5f9;color:#64748b;font-size:0.78rem;font-weight:600;padding:3px 9px;border-radius:999px;"><?php echo count($reports); ?> total</span>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <div style="position:relative;">
            <i class="fas fa-search" style="position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:0.85rem;"></i>
            <input type="text" id="reportSearch" placeholder="Search by ID, type, location..." style="padding:9px 14px 9px 38px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:0.88rem;outline:none;width:220px;background:#f8fafc;color:#334155;transition:all 0.2s;" onfocus="this.style.borderColor='#10b981';this.style.boxShadow='0 0 0 3px rgba(16,185,129,0.1)'" onblur="this.style.borderColor='#e2e8f0';this.style.boxShadow='none'" onkeyup="filterReports()">
          </div>
          <select id="reportTypeFilter" onchange="filterReports()" style="padding:9px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:0.88rem;outline:none;background:#f8fafc;color:#334155;cursor:pointer;">
            <option value="">All Report Types</option>
            <option value="stray">Stray Animal</option>
            <option value="rabies">Rabies Case</option>
            <option value="injured">Injured Animal</option>
            <option value="dead">Dead Animal</option>
          </select>
          <select id="reportStatusFilter" onchange="filterReports()" style="padding:9px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:0.88rem;outline:none;background:#f8fafc;color:#334155;cursor:pointer;">
            <option value="">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="validated">Validated</option>
            <option value="confirmed">Confirmed</option>
            <option value="rejected">Rejected</option>
            <option value="resolved">Resolved</option>
          </select>
        </div>
      </div>
      <?php if (empty($reports)): ?>
        <table class="data-table"><tbody><tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:2rem;"><i class="fas fa-search" style="font-size:1.5rem;display:block;margin-bottom:8px;opacity:0.4;"></i>No reports submitted yet.</td></tr></tbody></table>
      <?php else: ?>
        <table class="data-table" id="reportsTable">
          <thead>
            <tr>
              <th style="width:50px;">No.</th>
              <th style="font-weight:700;">Report ID</th>
              <th>Type</th>
              <th>Location</th>
              <th>Date Submitted</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $rNum = 1; foreach ($reports as $report):
            ?>
              <tr>
                <td style="color:#94a3b8;font-size:0.85rem;font-weight:600;"><?php echo $rNum++; ?></td>
                <td style="font-family:monospace; color:#475569; font-weight:700;"><?php echo htmlspecialchars($report['reference_id'] ?? '#RPT-'.str_pad($report['report_id'], 4, '0', STR_PAD_LEFT)); ?></td>
                <td><?php echo ucfirst($report['report_type']); ?></td>
                <td><?php echo htmlspecialchars($report['location_address']); ?></td>
                <td style="color:#64748b;font-size:0.85rem;"><?php echo date('M d, Y', strtotime($report['created_at'])); ?></td>
                <td><strong style="font-size:0.82rem;"><?php echo ucfirst($report['status']); ?></strong></td>
                <td style="white-space:nowrap;">
                  <?php if (in_array($report['status'], ['pending', 'rejected'])): ?>
                  <button class="btn-sm btn-secondary" onclick="editReport(this)" title="Edit"
                    data-report-id="<?php echo $report['report_id']; ?>"
                    data-report-type="<?php echo htmlspecialchars($report['report_type']); ?>"
                    data-location="<?php echo htmlspecialchars($report['location_address']); ?>"
                    data-description="<?php echo htmlspecialchars($report['description']); ?>"
                    data-urgency="<?php echo htmlspecialchars($report['urgency_level'] ?? 'low'); ?>"
                    data-created-at="<?php echo date('Y-m-d\TH:i', strtotime($report['created_at'])); ?>"><i class="fas fa-edit"></i></button>
                  <?php endif; ?>
                  <button class="btn-sm btn-secondary" onclick="archiveReport(<?php echo $report['report_id']; ?>)" title="Archive"><i class="fas fa-archive"></i></button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </section>

  <!-- ────────── APPOINTMENTS ────────── -->
  <section id="appointments" class="content-section">
    <div class="page-header">
      <div>
        <h1 class="page-title">Appointments</h1>
        <p class="page-subtitle">Your adoption and kapon appointment requests</p>
      </div>
      <button class="btn-primary" onclick="openModal('appointmentModal')">
        <i class="fas fa-plus"></i> Request Appointment
      </button>
    </div>

    <div class="table-card">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;padding-bottom:14px;border-bottom:1px solid #f1f5f9;margin-bottom:14px;">
        <span data-count="appointments" style="background:#f1f5f9;color:#64748b;font-size:0.78rem;font-weight:600;padding:3px 9px;border-radius:999px;"><?php echo count($appointments); ?> total</span>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <div style="position:relative;">
            <i class="fas fa-search" style="position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:0.85rem;"></i>
            <input type="text" id="apptSearch" placeholder="Search by ID, type..." style="padding:9px 14px 9px 38px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:0.88rem;outline:none;width:220px;background:#f8fafc;color:#334155;transition:all 0.2s;" onfocus="this.style.borderColor='#10b981';this.style.boxShadow='0 0 0 3px rgba(16,185,129,0.1)'" onblur="this.style.borderColor='#e2e8f0';this.style.boxShadow='none'" onkeyup="filterAppointments()">
          </div>
          <select id="apptStatusFilter" onchange="filterAppointments()" style="padding:9px 14px;border:1.5px solid #e2e8f0;border-radius:10px;font-size:0.88rem;outline:none;background:#f8fafc;color:#334155;cursor:pointer;">
            <option value="">All Status</option>
            <option value="pending">Pending</option>
            <option value="scheduled">Scheduled</option>
            <option value="completed">Completed</option>
            <option value="cancelled">Cancelled</option>
            <option value="rescheduled">Rescheduled</option>
          </select>
        </div>
      </div>

      <div id="tab-upcoming" class="tab-content active">
        <?php if (empty($appointments)): ?>
          <table class="data-table"><tbody><tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:2rem;"><i class="fas fa-search" style="font-size:1.5rem;display:block;margin-bottom:8px;opacity:0.4;"></i>No upcoming appointments.</td></tr></tbody></table>
        <?php else: ?>
          <table class="data-table" id="appointmentsTable">
            <thead>
              <tr>
                <th style="width:50px;">No.</th>
                <th style="font-weight:700;">Request ID</th>
                <th>Service</th>
                <th>Pet Name</th>
                <th>Date</th>
                <th>Time</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $aNum = 1; foreach ($appointments as $appt):
              ?>
              <tr data-id="<?php echo (int)$appt['appointment_id']; ?>">
                <td style="color:#94a3b8;font-size:0.85rem;font-weight:600;"><?php echo $aNum++; ?></td>
                <td style="font-family:monospace; color:#475569; font-weight:700;"><?php echo htmlspecialchars($appt['reference_id'] ?? fallbackRefId('#APT-', $appt['appointment_id'])); ?></td>
                <td><?php echo ucfirst($appt['appointment_type']); ?></td>
                <td><?php echo htmlspecialchars($appt['pet_name'] ?? '-'); ?></td>
                <td><?php echo date('M d, Y', strtotime($appt['preferred_date'])); ?></td>
                <td><?php echo date('g:i A', strtotime($appt['preferred_time'])); ?></td>
                <td><strong style="font-size:0.82rem;"><?php echo ucfirst(str_replace('_',' ',$appt['status'])); ?></strong></td>
                <td style="white-space:nowrap;">
                  <?php if ($appt['status'] === 'pending'): ?>
                  <button class="btn-sm btn-secondary" onclick="editAppointment(this)" title="Edit"
                    data-appointment-id="<?php echo $appt['appointment_id']; ?>"
                    data-appointment-type="<?php echo htmlspecialchars($appt['appointment_type']); ?>"
                    data-pet-id="<?php echo htmlspecialchars($appt['pet_id'] ?? ''); ?>"
                    data-preferred-date="<?php echo htmlspecialchars($appt['preferred_date']); ?>"
                    data-preferred-time="<?php echo htmlspecialchars($appt['preferred_time']); ?>"
                     data-notes="<?php echo htmlspecialchars($appt['notes'] ?? ''); ?>"><i class="fas fa-edit"></i></button>
                  <?php endif; ?>
                  <button class="btn-sm btn-secondary" onclick="archiveAppointment(<?php echo $appt['appointment_id']; ?>)" title="Archive"><i class="fas fa-archive"></i></button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      </div>

      <div id="tab-past" class="tab-content"></div>

      <div id="tab-cancelled" class="tab-content"></div>
    </div>
  </section>

  <!-- ────────── ARCHIVE ────────── -->
  <section id="archive" class="content-section">
    <div class="page-header">
      <div>
        <h1 class="page-title">Archive</h1>
        <p class="page-subtitle">View all your archived items</p>
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

    <!-- Archived Pets -->
    <div class="archive-section" data-type="pet">
      <h2>Archived Pets (<?php echo count($archivedPets); ?>)</h2>
      <?php if (empty($archivedPets)): ?>
        <p style="text-align:center;color:#8bb89a;padding:2rem;">No archived pets.</p>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Species</th>
              <th>Breed</th>
              <th>Gender</th>
              <th>Age</th>
              <th>Photo</th>
              <th>Archived Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($archivedPets as $pet): ?>
              <tr>
                <td style="font-family:monospace; color:#475569; font-weight:700;"><?php echo htmlspecialchars($pet['reference_id'] ?? fallbackRefId('#PET-', $pet['pet_id'])); ?></td>
                <td><strong><?php echo htmlspecialchars($pet['pet_name']); ?></strong></td>
                <td><?php echo ucfirst($pet['pet_type']); ?></td>
                <td><?php echo htmlspecialchars($pet['breed'] ?? '-'); ?></td>
                <td><?php echo ucfirst($pet['gender']); ?></td>
                <td><?php echo $pet['age']; ?> yrs</td>
                <td>
                  <?php if (!empty($pet['photo_url'])): ?>
                    <button class="btn-sm btn-secondary" onclick="viewPetImage('<?php echo htmlspecialchars($pet['photo_url']); ?>')">
                      <i class="fas fa-image"></i> View
                    </button>
                  <?php else: ?>
                    <span style="color:#999;font-size:0.85rem;">No photo</span>
                  <?php endif; ?>
                </td>
                <td><?php echo date('M d, Y', strtotime($pet['archived_at'])); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- Archived Reports -->
    <div class="archive-section" data-type="report">
      <h2>Archived Reports (<?php echo count($archivedReports); ?>)</h2>
      <?php if (empty($archivedReports)): ?>
        <p style="text-align:center;color:#8bb89a;padding:2rem;">No archived reports.</p>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Type</th>
              <th>Location</th>
              <th>Date</th>
              <th>Status</th>
              <th>Photo</th>
              <th>Archived Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($archivedReports as $report): ?>
              <tr>
                <td style="font-family:monospace; color:#475569; font-weight:700;"><?php echo htmlspecialchars($report['reference_id'] ?? fallbackRefId('#RPT-', $report['report_id'])); ?></td>
                <td><?php echo ucfirst($report['report_type']); ?></td>
                <td><?php echo htmlspecialchars($report['location_address']); ?></td>
                <td><?php echo date('M d, Y', strtotime($report['created_at'])); ?></td>
                <td><strong style="font-size:0.82rem;"><?php echo ucfirst($report['status']); ?></strong></td>
                <td>
                  <?php if (!empty($report['photo_url'])): ?>
                    <button class="btn-sm btn-secondary" onclick="viewReportImage('<?php echo htmlspecialchars($report['photo_url']); ?>')">
                      <i class="fas fa-image"></i> View
                    </button>
                  <?php else: ?>
                    <span style="color:#999;font-size:0.85rem;">No photo</span>
                  <?php endif; ?>
                </td>
                <td><?php echo date('M d, Y', strtotime($report['archived_at'])); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- Archived Appointments -->
    <div class="archive-section" data-type="appointment">
      <h2>Archived Appointments (<?php echo count($archivedAppointments); ?>)</h2>
      <?php if (empty($archivedAppointments)): ?>
        <p style="text-align:center;color:#8bb89a;padding:2rem;">No archived appointments.</p>
      <?php else: ?>
        <div class="appointments-list">
          <?php foreach ($archivedAppointments as $appt): ?>
            <div class="appointment-card">
              <div class="appt-icon">
                <i class="fas fa-<?php echo $appt['appointment_type'] === 'adoption' ? 'heart' : 'cut'; ?>"></i>
              </div>
              <div class="appt-info">
                <h4 class="appt-type"><?php echo ucfirst($appt['appointment_type']); ?></h4>
                <p class="appt-datetime">
                  <i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($appt['preferred_date'])); ?>
                  <i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($appt['preferred_time'])); ?>
                </p>
                <p class="appt-datetime">
                  <i class="fas fa-archive"></i> Archived: <?php echo date('M d, Y', strtotime($appt['archived_at'])); ?>
                </p>
                <strong style="font-size:0.82rem;"><?php echo ucfirst($appt['status']); ?></strong>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ────────── ADOPTION LISTINGS ────────── -->
  <section id="adoption" class="content-section">
    <?php
      $catCounts = ['active'=>0,'pending'=>0,'completed'=>0,'cancelled'=>0];
      foreach ($adoptionListings as $l) {
        $ha = isset($l['appt_status']) && in_array($l['appt_status'], ['pending','scheduled','rescheduled']);
        $isAd = ($l['appt_status'] === 'completed') || ($l['status'] === 'adopted');
        $isCa = ($l['appt_status'] === 'cancelled');
        $c = $isAd ? 'completed' : ($ha ? 'pending' : ($isCa ? 'cancelled' : 'active'));
        $catCounts[$c]++;
      }
      $totalAdopt = count($adoptionListings);
    ?>
    <div class="adopt-wrap">
      <div class="page-header">
        <div>
          <h1 class="page-title">My Adoption Listings</h1>
          <p class="page-subtitle">Pets you registered for adoption — schedule an appointment to finalize.</p>
        </div>
        <a href="petlist.php" target="_blank" class="btn-primary" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.6rem 1.25rem;font-size:0.85rem;text-decoration:none;white-space:nowrap;"><i class="fas fa-eye"></i> View Available Pets</a>
      </div>

      <div class="adopt-toolbar">
        <div class="adopt-pills" id="adoptPills">
          <button class="adopt-pill active" data-filter="all" onclick="setAdoptFilter('all',this)">All (<?php echo $totalAdopt; ?>)</button>
          <button class="adopt-pill" data-filter="active" onclick="setAdoptFilter('active',this)">Active (<?php echo $catCounts['active']; ?>)</button>
          <button class="adopt-pill" data-filter="pending" onclick="setAdoptFilter('pending',this)">Pending (<?php echo $catCounts['pending']; ?>)</button>
          <button class="adopt-pill" data-filter="completed" onclick="setAdoptFilter('completed',this)">Completed (<?php echo $catCounts['completed']; ?>)</button>
          <button class="adopt-pill" data-filter="cancelled" onclick="setAdoptFilter('cancelled',this)">Cancelled (<?php echo $catCounts['cancelled']; ?>)</button>
        </div>
        <div class="adopt-tools">
          <div class="adopt-search"><i class="fas fa-search"></i><input type="text" id="adoptSearch" placeholder="Search by ID, type..." oninput="filterAdopt()" /></div>
        </div>
      </div>

      <?php if (empty($adoptionListings)): ?>
        <div class="adopt-empty">You haven't registered any pets for adoption yet. Click "New Adoption Listing" to get started.</div>
      <?php else: ?>
        <div class="adopt-grid" id="adoptGrid">
          <?php foreach ($adoptionListings as $listing):
            $isAdopted = ($listing['appt_status'] === 'completed') || ($listing['status'] === 'adopted');
            $apptPending = in_array($listing['appt_status'] ?? '', ['pending','scheduled','rescheduled']);
            $isCancelled = $listing['appt_status'] === 'cancelled';
            $isPending = $listing['status'] === 'pending' || $apptPending;
            if ($isAdopted)      { $filterCat='completed'; $statusCls='adopted';  $statusTxt='Adopted';            $stageTxt='Completed'; }
            elseif ($isPending)  { $filterCat='pending';    $statusCls='pending';   $statusTxt='Pending Intake';    $stageTxt='Intake'; }
            elseif ($isCancelled){ $filterCat='cancelled';  $statusCls='cancelled'; $statusTxt='Cancelled';         $stageTxt='Cancelled'; }
            else                 { $filterCat='active';     $statusCls='available'; $statusTxt='Ready for Adoption'; $stageTxt='Ready for Adoption'; }
            $petRef = $listing['pet_ref_id'] ?? fallbackRefId('#PET-', $listing['pet_id']);
            $species = htmlspecialchars(ucfirst($listing['pet_type'] ?? 'Dog'));
            $gender = htmlspecialchars(ucfirst($listing['gender'] ?? '-'));
            $ageN = (int)($listing['age'] ?? 0);
            $ageVal = $ageN > 0 ? ($ageN . ' Year' . ($ageN > 1 ? 's' : '')) : '-';
            $regOn = date('M j, Y', strtotime($listing['created_at']));
            $breedUp = htmlspecialchars(($listing['breed'] ?? '') ?: strtoupper($listing['pet_type'] ?? 'PET'));
            $desc = htmlspecialchars(($listing['description'] ?: $listing['source_description']) ?: ($listing['pet_name'] . "'s adoption listing. Looking for a loving forever home."));
            $reqRef = $listing['appt_ref'] ?: (!empty($listing['appt_id']) ? fallbackRefId('#APT-', $listing['appt_id']) : $petRef);
          ?>
          <article class="adopt-card" data-cat="<?php echo $filterCat; ?>" data-name="<?php echo htmlspecialchars(strtolower($listing['pet_name'] ?? '')); ?>" data-type="<?php echo htmlspecialchars(strtolower($listing['pet_type'] ?? '')); ?>" data-date="<?php echo strtotime($listing['created_at']); ?>">
            <div class="adopt-badges">
              <span class="adopt-ref-badge"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($petRef); ?></span>
            </div>
            <div class="adopt-split">
              <div class="adopt-media">
                <?php if (!empty($listing['photo_url'])): ?>
                  <img src="<?php echo htmlspecialchars($listing['photo_url']); ?>" alt="<?php echo htmlspecialchars($listing['pet_name']); ?>" />
                <?php else: ?>
                  <div class="adopt-media-plain">No Photo</div>
                <?php endif; ?>
              </div>
              <div class="adopt-info">
                <h2 class="adopt-name"><?php echo htmlspecialchars($listing['pet_name']); ?></h2>
                <div class="adopt-breed"><?php echo $breedUp; ?></div>
                <p class="adopt-desc"><?php echo $desc; ?></p>
                <hr class="adopt-divider" />
                <div class="adopt-info-grid">
                  <div class="adopt-info-item"><span class="icon"><i class="fas fa-calendar-alt"></i></span><div><div class="ic-label">Registered</div><div class="ic-value"><?php echo htmlspecialchars($regOn); ?></div></div></div>
                  <div class="adopt-info-item"><span class="icon"><i class="fas fa-tag"></i></span><div><div class="ic-label">Request ID</div><div class="ic-value"><?php echo htmlspecialchars($reqRef); ?></div></div></div>
                  <?php if (!empty($listing['appt_id']) && !empty($listing['appt_date'])): ?>
                  <div class="adopt-info-item"><span class="icon"><i class="fas fa-clock"></i></span><div><div class="ic-label">Scheduled</div><div class="ic-value"><?php echo date('M j, Y', strtotime($listing['appt_date'])); ?></div></div></div>
                  <?php endif; ?>
                  <div class="adopt-info-item"><span class="icon"><i class="fas fa-map-marker-alt"></i></span><div><div class="ic-label">Location</div><div class="ic-value">Dasmariñas City, Cavite</div></div></div>
                  <div class="adopt-info-item"><span class="icon"><i class="fas fa-birthday-cake"></i></span><div><div class="ic-label">Age</div><div class="ic-value"><?php echo $ageVal; ?></div></div></div>
                  <div class="adopt-info-item"><span class="icon"><i class="fas fa-venus-mars"></i></span><div><div class="ic-label">Gender</div><div class="ic-value"><?php echo $gender; ?></div></div></div>
                  <div class="adopt-info-item"><span class="icon"><i class="fas fa-paw"></i></span><div><div class="ic-label">Species</div><div class="ic-value"><?php echo $species; ?></div></div></div>
                </div>
                <div class="adopt-actions">
                  <?php if ($isAdopted): ?>
                    <span class="adopt-status-btn adopted"><i class="fas fa-check-circle"></i> Home Confirmed</span>
                  <?php elseif (in_array($listing['appt_status'] ?? '', ['scheduled','rescheduled'])): ?>
                    <!-- scheduled: date already shown in the info grid -->
                  <?php elseif ($listing['status'] === 'pending' || (($listing['appt_status'] ?? '') === 'pending')): ?>
                    <span class="adopt-status-btn pending"><i class="fas fa-hourglass-half"></i> Awaiting Staff Confirmation</span>
                  <?php else: ?>
                    <button type="button" class="adopt-status-btn available" onclick="openAdoptionAppt(<?php echo (int)$listing['pet_id']; ?>, '<?php echo htmlspecialchars($listing['pet_name'], ENT_QUOTES); ?>')"><i class="fas fa-calendar-plus"></i> Schedule Appointment</button>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="adopt-footer">
        <div class="adopt-footer-icon"><i class="fas fa-paw"></i></div>
        <h3>Thank you for giving a pet a chance!</h3>
        <p>Your compassion makes a difference.</p>
      </div>
    </div>
  </section>

  <!-- ────────── ACCOUNT SETTINGS ────────── -->
  <section id="account-settings" class="content-section">
    <div class="page-header">
      <div>
        <h1 class="page-title" style="font-size:22px;font-weight:800;margin:0;">My Profile</h1>
        <p class="page-subtitle">Manage your profile and account settings</p>
      </div>
    </div>

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
          <button type="button" class="ps-btn ps-btn-secondary" onclick="openModal('changePasswordModal')">Change Password</button>
          <button type="submit" class="ps-btn ps-btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </section>

</main>

<!-- ══════════════════════════════════════════
     MODALS
══════════════════════════════════════════ -->

<!-- Register Pet Modal -->
<div class="modal" id="addPetModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2><?php echo $showAdoptModal ? 'Register Pet for Adoption' : 'Register a Pet'; ?></h2>
      <button class="modal-close" onclick="closeModal('addPetModal')"><i class="fas fa-times"></i></button>
    </div>
    <form class="modal-form" id="addPetForm" action="add-pet.php" method="POST" enctype="multipart/form-data">
      <input type="hidden" name="return_section" value="my-pets">
      <div class="modal-body">
      <?php if ($showAdoptModal && $adoptData): ?>
        <div class="adoption-info-card" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.5rem;padding:0.75rem;margin-bottom:1rem;font-size:0.85rem;">
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
      <div class="form-row">
        <div class="form-group"><label>Pet Name <span class="req">*</span></label>
          <?php if ($showAdoptModal): ?>
            <p style="padding:0.6rem 0;color:var(--text);font-weight:500;"><?php echo htmlspecialchars($adoptData['name'] ?? ''); ?></p>
          <?php else: ?>
            <input type="text" name="pet_name" placeholder="e.g. Max" required />
          <?php endif; ?>
        </div>
        <div class="form-group"><label>Species <span class="req">*</span></label>
          <?php if ($showAdoptModal): ?>
            <p style="padding:0.6rem 0;color:var(--text);font-weight:500;"><?php echo ucfirst(htmlspecialchars($adoptData['species'] ?? '')); ?></p>
          <?php else: ?>
            <select name="species" id="addPetSpecies" required onchange="toggleCustomSpecies('addPetSpecies','addPetSpeciesCustom')">
              <option value="">Select</option>
              <option value="dog">Dog</option>
              <option value="cat">Cat</option>
              <option value="other">Other</option>
            </select>
            <input type="text" name="species_custom" id="addPetSpeciesCustom" placeholder="Specify species" style="display:none;margin-top:6px;" />
          <?php endif; ?>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Breed</label>
          <?php if ($showAdoptModal): ?>
            <p style="padding:0.6rem 0;color:var(--text);font-weight:500;"><?php echo htmlspecialchars($adoptData['breed'] ?? '-'); ?></p>
          <?php else: ?>
            <input type="text" name="breed" placeholder="e.g. Aspin" />
          <?php endif; ?>
        </div>
        <div class="form-group"><label>Gender <span class="req">*</span></label>
          <?php if ($showAdoptModal): ?>
            <p style="padding:0.6rem 0;color:var(--text);font-weight:500;"><?php echo ucfirst(htmlspecialchars($adoptData['gender'] ?? '')); ?></p>
          <?php else: ?>
            <select name="gender" required>
              <option value="">Select</option>
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
          <?php endif; ?>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Age (years)</label>
          <?php if ($showAdoptModal): ?>
            <p style="padding:0.6rem 0;color:var(--text);font-weight:500;"><?php echo htmlspecialchars((string)($adoptData['age'] ?? '')); ?> yr<?php echo ($adoptData['age'] ?? 0) > 1 ? 's' : ''; ?></p>
          <?php else: ?>
            <input type="number" name="age" min="0" placeholder="e.g. 2" />
          <?php endif; ?>
        </div>
        <div class="form-group"><label>Weight (kg)</label>
          <?php if ($showAdoptModal): ?>
            <p style="padding:0.6rem 0;color:var(--text);font-weight:500;"><?php echo htmlspecialchars((string)($adoptData['weight'] ?? '')); ?> kg</p>
          <?php else: ?>
            <input type="number" name="weight" step="0.1" placeholder="e.g. 5.5" />
          <?php endif; ?>
        </div>
      </div>
      <div class="form-group">
        <label>Color / Markings</label>
        <?php if ($showAdoptModal): ?>
          <p style="padding:0.6rem 0;color:var(--text);font-weight:500;"><?php echo htmlspecialchars($adoptData['color'] ?? '-'); ?></p>
        <?php else: ?>
          <input type="text" name="color" placeholder="e.g. brown with white spots" />
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label>Vaccination Status</label>
        <select name="vaccinated">
          <option value="yes">Vaccinated</option>
          <option value="no">Not Vaccinated</option>
          <option value="partial">Partially Vaccinated</option>
        </select>
      </div>
      <?php if ($showAdoptModal && !empty($adoptData['photo'])): ?>
        <div class="form-group">
          <label>Pet Photo</label>
          <p style="margin:0;font-size:0.8rem;color:var(--text-3);">Photo from pet listing will be used.</p>
        </div>
      <?php else: ?>
      <div class="form-group">
        <label>Pet Photo (optional)</label>
        <input type="file" name="photo" accept="image/*" id="addPetPhoto" onchange="previewPetImage(this, 'addPetPhotoPreview')" />
        <div id="addPetPhotoPreview" style="margin-top: 10px; display: none;">
          <img src="" alt="Pet Photo Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px;" />
        </div>
        <small style="color: var(--text-3);">Upload a photo of your pet (max 5MB)</small>
      </div>
      <?php endif; ?>
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn-primary"><?php echo $showAdoptModal ? 'Register & Proceed to Adopt' : 'Register Pet'; ?></button>
        <button type="button" class="btn-secondary" onclick="closeModal('addPetModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Pet Modal -->
<div class="modal" id="editPetModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Edit Pet</h2>
      <button class="modal-close" onclick="closeModal('editPetModal')"><i class="fas fa-times"></i></button>
    </div>
    <form class="modal-form" id="editPetForm" action="edit-pet.php" method="POST" enctype="multipart/form-data">
      <input type="hidden" name="return_section" value="my-pets">
      <div class="modal-body">
      <input type="hidden" name="pet_id" id="editPetId">
      <div class="form-row">
        <div class="form-group"><label>Pet Name <span class="req">*</span></label><input type="text" name="pet_name" id="editPetName" placeholder="e.g. Max" required /></div>
        <div class="form-group"><label>Species <span class="req">*</span></label>
          <select name="species" id="editPetSpecies" required onchange="toggleCustomSpecies('editPetSpecies','editPetSpeciesCustom')">
            <option value="">Select</option>
            <option value="dog">Dog</option>
            <option value="cat">Cat</option>
            <option value="other">Other</option>
          </select>
          <input type="text" name="species_custom" id="editPetSpeciesCustom" placeholder="Specify species" style="display:none;margin-top:6px;" />
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Breed</label><input type="text" name="breed" id="editPetBreed" placeholder="e.g. Aspin" /></div>
        <div class="form-group"><label>Gender <span class="req">*</span></label>
          <select name="gender" id="editPetGender" required>
            <option value="">Select</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Age (years)</label><input type="number" name="age" id="editPetAge" min="0" placeholder="e.g. 2" /></div>
        <div class="form-group"><label>Weight (kg)</label><input type="number" name="weight" id="editPetWeight" step="0.1" placeholder="e.g. 5.5" /></div>
      </div>
      <div class="form-group">
        <label>Color / Markings</label>
        <input type="text" name="color" id="editPetColor" placeholder="e.g. brown with white spots" />
      </div>
      <div class="form-group">
        <label>Vaccination Status</label>
        <select name="vaccinated" id="editPetVaccinated">
          <option value="yes">Vaccinated</option>
          <option value="no">Not Vaccinated</option>
          <option value="partial">Partially Vaccinated</option>
        </select>
      </div>
      <div class="form-group">
        <label>Pet Photo (optional)</label>
        <div id="editPetCurrentPhoto" style="margin-bottom: 10px; display: none;">
          <p style="font-size: 0.85rem; color: var(--text-3); margin-bottom: 5px;">Current Photo:</p>
          <img id="editPetCurrentPhotoImg" src="" alt="Current Pet Photo" style="max-width: 200px; max-height: 200px; border-radius: 8px;" />
        </div>
        <input type="file" name="photo" accept="image/*" id="editPetPhoto" onchange="previewPetImage(this, 'editPetPhotoPreview')" />
        <div id="editPetPhotoPreview" style="margin-top: 10px; display: none;">
          <p style="font-size: 0.85rem; color: var(--text-3); margin-bottom: 5px;">New Photo Preview:</p>
          <img src="" alt="Pet Photo Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px;" />
        </div>
        <small style="color: var(--text-3);">Upload a new photo to replace the existing one (max 5MB)</small>
      </div>
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Update Pet</button>
        <button type="button" class="btn-secondary" onclick="closeModal('editPetModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Submit Report Modal -->
<div class="modal" id="submitReportModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Submit a Report</h2>
      <button class="modal-close" onclick="closeModal('submitReportModal')"><i class="fas fa-times"></i></button>
    </div>
    <form class="modal-form" id="submitReportForm" action="submit-report.php" method="POST" enctype="multipart/form-data">
      <input type="hidden" name="return_section" value="my-reports">
      <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label>Report Type <span class="req">*</span></label>
          <select name="report_type" required>
            <option value="">Select type</option>
            <?php
            $knownTypes = ['stray' => 'Stray Animal', 'rabies' => 'Rabies Case', 'injured' => 'Injured Animal', 'dead' => 'Dead Animal'];
            foreach ($knownTypes as $val => $label): ?>
            <option value="<?php echo $val; ?>"><?php echo $label; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Date/Time <span class="req">*</span></label>
          <input type="datetime-local" name="report_datetime" id="reportDatetime" min="<?php echo date('Y-m-d\TH:i'); ?>" required />
        </div>
      </div>
      <div class="form-group">
        <label>Animal Description <span class="req">*</span></label>
        <input type="text" name="animal_desc" placeholder="e.g. medium-sized brown dog, no collar" required />
      </div>
      <div class="form-group">
        <label>Location (Barangay/Street) <span class="req">*</span></label>
        <input type="text" name="location" placeholder="e.g. Brgy. <?php echo htmlspecialchars($barangayName); ?>, near the market" required />
      </div>
      <div class="form-group">
        <label>Detailed Description <span class="req">*</span></label>
        <textarea name="description" rows="4" placeholder="Describe what you observed, including the animal's condition, behavior, and any immediate actions taken..." required></textarea>
      </div>
      <div class="form-group">
        <label>Photo Evidence (optional)</label>
        <input type="file" name="photo" accept="image/*" />
        <small style="color: var(--text-3);">Upload a photo of the animal or situation (max 5MB)</small>
      </div>
      <div class="form-group">
        <label>Urgency Level</label>
        <select name="urgency">
          <option value="low">Low - Not urgent</option>
          <option value="medium" selected>Medium - Needs attention within 24 hours</option>
          <option value="high">High - Immediate attention required</option>
        </select>
      </div>
      <div class="modal-notice">
        <i class="fas fa-info-circle"></i>
        Your report will be sent to your <strong>barangay officer</strong> for validation before being forwarded to DCVS.
      </div>
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn-primary"><i class="fas fa-paper-plane"></i> Submit Report</button>
        <button type="button" class="btn-secondary" onclick="closeModal('submitReportModal')">Cancel</button>
      </div>
    </form>
  </div>
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

<!-- Appointment Modal -->
<div class="modal" id="appointmentModal">
  <div class="modal-content">
    <div class="modal-header">
      <button class="modal-close" onclick="closeModal('appointmentModal')"><i class="fas fa-times"></i></button>
    </div>
    <form class="modal-form" id="appointmentForm" action="request-appointment.php" method="POST">
      <input type="hidden" name="return_section" value="appointments">
      <input type="hidden" name="appt_date" id="apptDate">
      <input type="hidden" name="appt_time" id="apptTime">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label>Appointment Type <span class="req">*</span></label>
            <select name="appt_type" id="apptTypeSelect" required>
              <option value="">Select type</option>
              <option value="adoption">Adoption</option>
              <option value="kapon">Kapon (Spay/Neuter)</option>
            </select>
          </div>
          <div class="form-group"><label>Select Pet <span class="req">*</span></label>
            <select name="pet" id="petSelect" required>
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
        </div>

        <!-- Booking Calendar (reui style) -->
        <div class="bk-card" style="margin-bottom:14px;">
          <div class="bk-content">
            <div class="bk-content-main">
              <div class="bk-cal-nav">
                <button type="button" id="bkPrevBtn" class="bk-cal-btn" title="Previous month"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>
                <span class="bk-cal-title" id="bkMonthYear"></span>
                <button type="button" id="bkNextBtn" class="bk-cal-btn" title="Next month"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>
              </div>
              <div class="bk-weekdays">
                <span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span><span>Su</span>
              </div>
              <div class="bk-days" id="bkGridDates"></div>
            </div>

            <div class="bk-slot-panel">
              <div class="bk-slot-scroll" id="bkSlots"></div>
            </div>
          </div>
          <div class="bk-footer">
            <div class="bk-footer-confirm" id="bkConfirmText">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
              <span>Select a date and time for your appointment.</span>
            </div>
          </div>
        </div>

        <div id="slotStatus" style="font-size:0.82rem;padding:6px 10px;border-radius:6px;display:none;margin-bottom:12px;"></div>
        <div class="form-group"><label>Notes / Reason</label>
          <textarea name="notes" rows="3" placeholder="Any special notes or reasons for the appointment..."></textarea>
        </div>
      </div>
      <div class="modal-actions">
        <button type="submit" class="bk-confirm-btn" id="bkConfirmBtn" disabled>Confirm</button>
        <button type="button" class="btn-secondary" onclick="closeModal('appointmentModal')">Cancel</button>
      </div>
    </form>
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
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Update Report</button>
        <button type="button" class="btn-secondary" onclick="closeModal('editReportModal')">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Appointment Modal -->
<div class="modal" id="editAppointmentModal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Edit Appointment</h2>
      <button class="modal-close" onclick="closeModal('editAppointmentModal')"><i class="fas fa-times"></i></button>
    </div>
    <form class="modal-form" id="editAppointmentForm" action="edit-appointment.php" method="POST">
      <div class="modal-body">
      <input type="hidden" name="appointment_id" id="editAppointmentId">
      <div class="form-group"><label>Appointment Type <span class="req">*</span></label>
        <select name="appt_type" id="editAppointmentType" required>
          <option value="">Select type</option>
          <option value="adoption">Adoption</option>
          <option value="kapon">Kapon (Spay/Neuter)</option>
        </select>
      </div>
      <div class="form-group"><label>Select Pet <span class="req">*</span></label>
        <select name="pet" id="editAppointmentPet" required>
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
      <div class="form-row">
        <div class="form-group"><label>Preferred Date <span class="req">*</span></label><input type="date" name="appt_date" id="editAppointmentDate" min="<?php echo date('Y-m-d'); ?>" required /></div>
        <div class="form-group"><label>Preferred Time</label>
          <select name="appt_time" id="editAppointmentTime">
            <option value="09:00">9:00 AM</option>
            <option value="10:00">10:00 AM</option>
            <option value="11:00">11:00 AM</option>
            <option value="14:00">2:00 PM</option>
            <option value="15:00">3:00 PM</option>
          </select>
        </div>
      </div>
      <div id="editSlotStatus" style="font-size:0.82rem;padding:6px 10px;border-radius:6px;display:none;margin-top:4px;"></div>
      <div class="form-group"><label>Notes / Reason</label>
        <textarea name="notes" id="editAppointmentNotes" rows="3" placeholder="Any special notes or reasons for the appointment..."></textarea>
      </div>
      </div>
      <div class="modal-actions">
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Update Appointment</button>
        <button type="button" class="btn-secondary" onclick="closeModal('editAppointmentModal')">Cancel</button>
      </div>
    </form>
  </div>
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
        <button type="submit" class="btn-primary"><i class="fas fa-paper-plane"></i> Submit Application</button>
        <button type="button" class="btn-secondary" onclick="closeModal('adoptionModal')">Cancel</button>
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
        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Change Password</button>
        <button type="button" class="btn-secondary" onclick="closeModal('changePasswordModal')">Cancel</button>
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
<div class="modal" id="confirmModal">
  <div class="modal-content">
    <div class="modal-body">
      <div style="text-align:center;">
        <h3 id="confirmTitle" style="margin-bottom:1rem;color:var(--text);">Confirm</h3>
        <p id="confirmMessage" style="color:var(--text-2);margin-bottom:2rem;">Are you sure?</p>
      </div>
      <div class="modal-actions" style="justify-content:center;">
        <a id="confirmYesBtn" href="#" class="btn-primary">Yes</a>
        <button type="button" class="btn-secondary" onclick="closeModal('confirmModal')">Cancel</button>
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
    document.getElementById('apptTypeSelect').value = 'adoption';
    document.getElementById('petSelect').value = petId;
    openModal('appointmentModal');
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

function filterPets() {
    const q = (document.getElementById('petSearch')?.value || '').toLowerCase();
    const t = document.getElementById('petTypeFilter')?.value || '';
    const s = document.getElementById('petStatusFilter')?.value || '';
    document.querySelectorAll('#my-pets .data-table tbody tr').forEach(r => {
        const name = (r.cells[2]?.textContent || '').toLowerCase();
        const type = (r.cells[3]?.textContent || '').toLowerCase();
        const vax = (r.cells[6]?.textContent || '').toLowerCase();
        const matchName = !q || name.includes(q) || (r.cells[4]?.textContent || '').toLowerCase().includes(q);
        const matchType = !t || type === t.toLowerCase();
        const matchVax = !s || vax.includes(s.toLowerCase());
        r.style.display = matchName && matchType && matchVax ? '' : 'none';
    });
}
function filterReports() {
    const q = (document.getElementById('reportSearch')?.value || '').toLowerCase();
    const t = document.getElementById('reportTypeFilter')?.value || '';
    const s = document.getElementById('reportStatusFilter')?.value || '';
    document.querySelectorAll('#my-reports .data-table tbody tr').forEach(r => {
        const id = (r.cells[1]?.textContent || '').toLowerCase();
        const type = (r.cells[2]?.textContent || '').toLowerCase();
        const loc = (r.cells[3]?.textContent || '').toLowerCase();
        const status = (r.cells[5]?.textContent || '').toLowerCase();
        const matchQ = !q || id.includes(q) || type.includes(q) || loc.includes(q);
        const matchT = !t || type === t.toLowerCase();
        const matchS = !s || status === s.toLowerCase();
        r.style.display = matchQ && matchT && matchS ? '' : 'none';
    });
}
function filterAppointments() {
    const q = (document.getElementById('apptSearch')?.value || '').toLowerCase();
    const s = document.getElementById('apptStatusFilter')?.value || '';
    document.querySelectorAll('#appointments .data-table tbody tr').forEach(r => {
        const id = (r.cells[1]?.textContent || '').toLowerCase();
        const type = (r.cells[2]?.textContent || '').toLowerCase();
        const status = (r.cells[6]?.textContent || '').toLowerCase();
        const matchQ = !q || id.includes(q) || type.includes(q);
        const matchS = !s || status === s.toLowerCase();
        r.style.display = matchQ && matchS ? '' : 'none';
    });
}
function filterArchive() {
    const q = (document.getElementById('archiveSearch')?.value || '').toLowerCase();
    const t = document.getElementById('archiveTypeFilter')?.value || '';
    document.querySelectorAll('#archive .data-table tbody tr').forEach(r => {
        const type = (r.cells[1]?.textContent || '').toLowerCase();
        const name = (r.cells[2]?.textContent || '').toLowerCase();
        const matchQ = !q || name.includes(q) || (r.cells[0]?.textContent || '').toLowerCase().includes(q);
        const matchT = !t || type === t.toLowerCase();
        r.style.display = matchQ && matchT ? '' : 'none';
    });
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

// ── Booking calendar (reui style) inside Request Appointment modal ──
(function() {
    var BK_SLOTS = ['09:00', '10:00', '11:00', '14:00', '15:00'];
    var bkMonthYear = document.getElementById('bkMonthYear');
    var bkGrid = document.getElementById('bkGridDates');
    var bkPrev = document.getElementById('bkPrevBtn');
    var bkNext = document.getElementById('bkNextBtn');
    var bkSlots = document.getElementById('bkSlots');
    var bkConfirmText = document.getElementById('bkConfirmText');
    var bkConfirmBtn = document.getElementById('bkConfirmBtn');
    if (!bkGrid) return;

    var today = new Date();
    var viewDate = new Date(today.getFullYear(), today.getMonth(), 1);
    var selectedDate = null;      // ISO 'YYYY-MM-DD'
    var selectedTime = null;      // 'HH:MM'
    var availability = {};        // { 'YYYY-MM-DD': { 'HH:MM': count } }
    var blockedSlots = {};        // { 'YYYY-MM-DD': { 'HH:MM': true } } — staff-managed closed slots
    var heldSlots = {};           // { 'YYYY-MM-DD': { 'HH:MM': true } } — slots with an active appointment

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

    function renderCalendar() {
        var year = viewDate.getFullYear();
        var month = viewDate.getMonth();
        var monthNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
        bkMonthYear.textContent = monthNames[month] + ', ' + year;

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
            if (selectedDate === key) div.classList.add('bk-day-selected');

            if (!div.disabled) {
                div.addEventListener('click', function() {
                    selectDay(this.getAttribute('data-date'));
                });
            }
            bkGrid.appendChild(div);
        }

        renderSlots();
    }

    function selectDay(key) {
        selectedDate = key;
        selectedTime = null;
        document.getElementById('apptDate').value = '';
        document.getElementById('apptTime').value = '';
        var cells = bkGrid.querySelectorAll('.bk-day');
        cells.forEach(function(c) { c.classList.toggle('bk-day-selected', c.getAttribute('data-date') === key); });
        renderSlots();
        updateConfirm();
    }

    function renderSlots() {
        bkSlots.innerHTML = '';
        if (!selectedDate) {
            var hint = document.createElement('div');
            hint.style.cssText = 'font-size:0.78rem;color:#94a3b8;padding:6px 4px;';
            hint.textContent = 'Select a date to see available times.';
            bkSlots.appendChild(hint);
            return;
        }
        var counts = availability[selectedDate] || {};
        var anyOpen = false;
        BK_SLOTS.forEach(function(s) {
            var blocked = isBlocked(selectedDate, s);
            var held = isHeld(selectedDate, s);
            var booked = counts[s] || 0;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'bk-slot-btn';
            btn.setAttribute('data-time', s);
            if (blocked) {
                var blSpan = document.createElement('span');
                blSpan.className = 'bk-slot-count';
                blSpan.textContent = 'Closed';
                btn.textContent = fmtTime(s);
                btn.appendChild(blSpan);
                btn.disabled = true;
            } else if (held || booked >= 1) {
                var heldSpan = document.createElement('span');
                heldSpan.className = 'bk-slot-count';
                heldSpan.textContent = 'Reserved';
                btn.textContent = fmtTime(s);
                btn.appendChild(heldSpan);
                btn.disabled = true;
            } else {
                anyOpen = true;
                var countSpan = document.createElement('span');
                countSpan.className = 'bk-slot-count';
                countSpan.textContent = 'Available';
                btn.textContent = fmtTime(s);
                btn.appendChild(countSpan);
                btn.addEventListener('click', function() {
                    selectTime(this.getAttribute('data-time'));
                });
            }
            bkSlots.appendChild(btn);
        });
        if (!anyOpen) {
            var none = document.createElement('div');
            none.style.cssText = 'font-size:0.78rem;color:#dc2626;padding:6px 4px;';
            none.textContent = 'This day is fully booked. Please pick another date.';
            bkSlots.appendChild(none);
        }
    }

    function selectTime(time) {
        selectedTime = time;
        document.getElementById('apptDate').value = selectedDate;
        document.getElementById('apptTime').value = time;
        var btns = bkSlots.querySelectorAll('.bk-slot-btn');
        btns.forEach(function(b) {
            b.classList.toggle('bk-slot-selected', b.getAttribute('data-time') === time);
        });
        updateConfirm();
    }

    function updateConfirm() {
        var confirmWrap = document.getElementById('bkConfirmText');
        if (selectedDate && selectedTime) {
            var dt = new Date(selectedDate + 'T00:00:00');
            confirmWrap.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg><span>Your appointment is booked for <strong>' +
                dt.toLocaleDateString('en-US', { weekday: 'long', day: 'numeric', month: 'long' }) +
                '</strong> at <strong>' + fmtTime(selectedTime) + '</strong>.</span>';
            bkConfirmBtn.disabled = false;
        } else {
            confirmWrap.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg><span>Select a date and time for your appointment.</span>';
            bkConfirmBtn.disabled = true;
        }
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

    function resetSelection() {
        selectedDate = null;
        selectedTime = null;
        document.getElementById('apptDate').value = '';
        document.getElementById('apptTime').value = '';
        updateConfirm();
    }

    fetchAvailability().then(renderCalendar);

    // Reset the booking calendar each time the modal opens
    var apptModal = document.getElementById('appointmentModal');
    if (apptModal && 'MutationObserver' in window) {
        new MutationObserver(function() {
            if (apptModal.classList.contains('active')) {
                selectedDate = null;
                selectedTime = null;
                viewDate = new Date(today.getFullYear(), today.getMonth(), 1);
                document.getElementById('apptDate').value = '';
                document.getElementById('apptTime').value = '';
                updateConfirm();
                fetchAvailability().then(renderCalendar);
            }
        }).observe(apptModal, { attributes: true, attributeFilter: ['class'] });
    }
})();

// Guard: prevent submitting the request without a picked date + time
document.addEventListener('DOMContentLoaded', function() {
    var apptForm = document.getElementById('appointmentForm');
    if (apptForm) {
        apptForm.addEventListener('submit', function(e) {
            var d = document.getElementById('apptDate').value;
            var t = document.getElementById('apptTime').value;
            if (!d || !t) {
                e.preventDefault();
                alert('Please select a date and an available time from the calendar.');
            }
        });
    }
});

function openAdoptionModal(listingId, petName) {
    openModal('adoptionModal');
}

// ── Adoption listings: filter / search / sort / more-menu ──
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

// ── Profile Settings Edit Toggle ──
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
  if (nav) nav.classList.add('active');
  var labels = {
    'overview':'Dashboard','my-pets':'My Pets','my-reports':'My Reports',
    'appointments':'Appointments','archive':'Archive','adoption':'Adoption Listings',

    'account-settings':'Account Settings'
  };
  var bc = document.getElementById('breadcrumbCurrent');
  if (bc) bc.textContent = labels[sectionId] || sectionId;
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
    // Appointments table
    if (data.appointments && data.appointments.length) {
        var tbody = document.querySelector('#appointmentsTable tbody');
        if (tbody) {
            data.appointments.forEach(function(appt) {
                if (tbody.querySelector('tr[data-id="' + appt.appointment_id + '"]')) return;
                var ref = appt.reference_id || '#APT-' + String(appt.appointment_id).padStart(4, '0');
                var type = appt.appointment_type.charAt(0).toUpperCase() + appt.appointment_type.slice(1);
                var dt = new Date(appt.preferred_date + 'T' + appt.preferred_time);
                var ds = dt.toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
                var ts = dt.toLocaleTimeString('en-US', {hour:'numeric',minute:'2-digit'});
                var st = appt.status.replace(/_/g,' '); st = st.charAt(0).toUpperCase() + st.slice(1);
                var tr = document.createElement('tr');
                tr.setAttribute('data-id', appt.appointment_id);
                tr.innerHTML = '<td style="color:#94a3b8;font-size:0.85rem;font-weight:600;">?</td>' +
                    '<td style="font-family:monospace;color:#475569;font-weight:700;">' + ref + '</td><td>' + type + '</td>' +
                    '<td>' + (appt.pet_name || '-') + '</td><td>' + ds + '</td><td>' + ts + '</td>' +
                    '<td><strong style="font-size:0.82rem;">' + st + '</strong></td>' +
                    '<td style="white-space:nowrap;">' +
                    (appt.status === 'pending' ? '<button class="btn-sm btn-secondary" title="Edit"><i class="fas fa-edit"></i></button> ' : '') +
                    '<button class="btn-sm btn-secondary" title="Archive"><i class="fas fa-archive"></i></button></td>';
                tbody.appendChild(tr);
                requestAnimationFrame(function(){tr.style.transition='opacity 0.4s';tr.style.opacity='1';});
                tr.style.opacity='0';
            });
        }
    }
    // Reports table
    if (data.reports && data.reports.length) {
        var tbody = document.querySelector('#reportsTable tbody');
        if (tbody) {
            data.reports.forEach(function(rpt) {
                if (tbody.querySelector('tr[data-id="' + rpt.report_id + '"]')) return;
                var ref = rpt.reference_id || '#RPT-' + String(rpt.report_id).padStart(4, '0');
                var type = rpt.report_type.charAt(0).toUpperCase() + rpt.report_type.slice(1);
                var dt = new Date(rpt.created_at.replace(' ','T'));
                var ds = dt.toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
                var st = rpt.status.charAt(0).toUpperCase() + rpt.status.slice(1);
                var tr = document.createElement('tr');
                tr.setAttribute('data-id', rpt.report_id);
                tr.innerHTML = '<td style="color:#94a3b8;font-size:0.85rem;font-weight:600;">?</td>' +
                    '<td style="font-family:monospace;color:#475569;font-weight:700;">' + ref + '</td><td>' + type + '</td>' +
                    '<td>' + (rpt.location_address || '-') + '</td><td>' + ds + '</td>' +
                    '<td><strong style="font-size:0.82rem;">' + st + '</strong></td>' +
                    '<td style="white-space:nowrap;">' +
                    ((rpt.status === 'pending' || rpt.status === 'rejected') ? '<button class="btn-sm btn-secondary" title="Edit"><i class="fas fa-edit"></i></button> ' : '') +
                    '<button class="btn-sm btn-secondary" title="Archive"><i class="fas fa-archive"></i></button></td>';
                tbody.appendChild(tr);
                requestAnimationFrame(function(){tr.style.transition='opacity 0.4s';tr.style.opacity='1';});
                tr.style.opacity='0';
            });
        }
    }
    // Pets table
    if (data.pets && data.pets.length) {
        var tbody = document.querySelector('#my-pets .data-table tbody');
        if (tbody) {
            data.pets.forEach(function(pet) {
                if (tbody.querySelector('tr[data-id="' + pet.pet_id + '"]')) return;
                var ref = pet.reference_id || '#PET-' + String(pet.pet_id).padStart(4, '0');
                var type = pet.pet_type.charAt(0).toUpperCase() + pet.pet_type.slice(1);
                var fa = pet.from_adoption ? 'Adopted' : 'Owned';
                var tr = document.createElement('tr');
                tr.setAttribute('data-id', pet.pet_id);
                tr.innerHTML = '<td style="color:#94a3b8;font-size:0.85rem;font-weight:600;">?</td>' +
                    '<td style="font-family:monospace;">' + ref + '</td>' +
                    '<td><strong>' + (pet.pet_name || '') + '</strong></td>' +
                    '<td>' + type + '</td><td>' + (pet.breed || '-') + '</td>' +
                    '<td><span class="badge-category">' + fa + '</span></td>' +
                    '<td><strong style="font-size:0.82rem;">' + (pet.vaccination_status || '-') + '</strong></td>' +
                    '<td style="white-space:nowrap;"><button class="btn-sm btn-secondary" title="View profile"><i class="fas fa-file-alt"></i></button> ' +
                    (!pet.from_adoption ? '<button class="btn-sm btn-secondary" title="Edit"><i class="fas fa-edit"></i></button> ' : '') +
                    '<button class="btn-sm btn-secondary" title="Archive"><i class="fas fa-archive"></i></button></td>';
                tbody.appendChild(tr);
                requestAnimationFrame(function(){tr.style.transition='opacity 0.4s';tr.style.opacity='1';});
                tr.style.opacity='0';
            });
        }
    }
});
</script>
</body>
</html>
















