<?php
$ref = $_GET['ref'] ?? '';

// Map ref â†’ session name
$sessionMap = [
    'resident' => 'PHPSESSID',
    'staff'    => 'DCVS_STAFF_SESSION',
    'admin'    => 'DCVS_ADMIN_SESSION',
    'barangay' => 'DCVS_BARANGAY_SESSION',
];
$sessionName = $sessionMap[$ref] ?? null;

// If ref didn't match or missing, try DCVS cookies, then fall back to PHPSESSID
if ($sessionName === null) {
    $dcvsPriority = ['DCVS_ADMIN_SESSION', 'DCVS_STAFF_SESSION', 'DCVS_BARANGAY_SESSION'];
    foreach ($dcvsPriority as $sn) {
        if (isset($_COOKIE[$sn])) {
            // Only use this DCVS cookie if it differs from the default session
            if (!isset($_COOKIE['PHPSESSID']) || $_COOKIE[$sn] !== $_COOKIE['PHPSESSID']) {
                $sessionName = $sn;
                break;
            }
        }
    }
}
if ($sessionName === null) {
    $sessionName = 'PHPSESSID';
}

ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path'     => '/',
    'domain'   => $_SERVER['HTTP_HOST'] ?? '',
    'secure'   => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name($sessionName);
session_start();
$role = $_SESSION['role'] ?? '';

require_once __DIR__ . '/../../db.php';

$petId = intval($_GET['id'] ?? 0);
if ($petId === 0) {
    die('Invalid pet ID.');
}

$isStaff = $role === 'staff';
$isAdmin = $role === 'admin';
$isResident = $role === 'resident';

if (!$isStaff && !$isAdmin && !$isResident) {
    header('Location: login.php');
    exit;
}

// Fetch pet + owner data
$pet = null;
$stmt = $conn->prepare("
    SELECT p.*, u.full_name AS owner_name, u.email AS owner_email, u.phone AS owner_phone,
           u.address AS owner_address, u.created_at AS owner_reg_date,
           b.barangay_name
    FROM pets p
    JOIN users u ON p.owner_id = u.user_id
    LEFT JOIN barangays b ON u.barangay_id = b.barangay_id
    WHERE p.pet_id = ? AND p.archived_at IS NULL
");
$stmt->bind_param("i", $petId);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $pet = $row;
}
$stmt->close();

if (!$pet) {
    die('Pet not found or has been archived.');
}

// Residents can only view their own pets
if ($isResident && $pet['owner_id'] !== $_SESSION['user_id']) {
    die('You do not have permission to view this pet profile.');
}

// Compute age display
$ageDisplay = $pet['age'] . ' yr' . ($pet['age'] != 1 ? 's' : '');
if (!empty($pet['date_of_birth'])) {
    $dob = new DateTime($pet['date_of_birth']);
    $now = new DateTime();
    $diff = $now->diff($dob);
    $ageDisplay = $diff->y . ' yr' . ($diff->y != 1 ? 's' : '');
    if ($diff->m > 0) $ageDisplay .= ' ' . $diff->m . ' mo' . ($diff->m != 1 ? 's' : '');
}

// Vaccination badge class
$vaxStatus = $pet['vaccination_status'] ?? '';
if (stripos($vaxStatus, 'fully') !== false || stripos($vaxStatus, 'fully vaccinated') !== false) {
    $badgeClass = 'vaccinated';
    $badgeText = 'Fully Vaccinated';
} elseif (stripos($vaxStatus, 'partial') !== false) {
    $badgeClass = 'pending';
    $badgeText = 'Partially Vaccinated';
} else {
    $badgeClass = 'unvaccinated';
    $badgeText = 'Not Vaccinated';
}

// Fetch completed appointments for timeline
$timeline = [];
// Registration event
$timeline[] = [
    'event' => 'Pet Registration Approved',
    'date' => $pet['created_at'],
    'details' => 'Registered by ' . htmlspecialchars($pet['owner_name']) . ' — ' . htmlspecialchars($pet['barangay_name'] ?? 'N/A'),
    'type' => 'registration'
];

// Completed appointments
$aptStmt = $conn->prepare("
    SELECT appointment_type, preferred_date, status, updated_at, notes
    FROM appointments
    WHERE pet_id = ? AND status IN ('completed','approved')
    ORDER BY preferred_date ASC
");
$aptStmt->bind_param("i", $petId);
$aptStmt->execute();
$aptResult = $aptStmt->get_result();
while ($apt = $aptResult->fetch_assoc()) {
    $typeLabels = [
        'vaccination' => 'Vaccination',
        'checkup' => 'Wellness Checkup',
        'kapon' => 'Kapon (Spay/Neuter)',
        'adoption' => 'Adoption Appointment'
    ];
    $eventLabel = $typeLabels[$apt['appointment_type']] ?? ucfirst($apt['appointment_type']);
    $timeline[] = [
        'event' => $eventLabel,
        'date' => $apt['preferred_date'],
        'details' => $apt['notes'] ?? 'Completed appointment',
        'type' => $apt['status'] === 'completed' ? 'completed' : 'scheduled'
    ];
}
$aptStmt->close();

// Sort timeline by date descending
usort($timeline, function ($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

// Fetch upcoming appointment
$upcomingAppt = null;
$uaStmt = $conn->prepare("
    SELECT appointment_type, preferred_date, preferred_time, status, notes
    FROM appointments
    WHERE pet_id = ? AND status IN ('pending','approved')
    ORDER BY preferred_date ASC
    LIMIT 1
");
$uaStmt->bind_param("i", $petId);
$uaStmt->execute();
$uaResult = $uaStmt->get_result();
if ($row = $uaResult->fetch_assoc()) {
    $upcomingAppt = $row;
}
$uaStmt->close();

// Fetch documents (check if table exists first)
$documents = [];
$docTableCheck = $conn->query("SHOW TABLES LIKE 'pet_documents'");
if ($docTableCheck && $docTableCheck->num_rows > 0) {
    $docStmt = $conn->prepare("
        SELECT document_id, document_type, file_name, file_url, description, created_at
        FROM pet_documents
        WHERE pet_id = ?
        ORDER BY created_at DESC
    ");
    if ($docStmt) {
        $docStmt->bind_param("i", $petId);
        $docStmt->execute();
        $docResult = $docStmt->get_result();
        while ($doc = $docResult->fetch_assoc()) {
            $documents[] = $doc;
        }
        $docStmt->close();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pet Profile — <?php echo htmlspecialchars($pet['pet_name']); ?> — DCVS</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
<style>
  :root{--green:#0E8131;--green-tint:#E7F5EA;--green-line:#BFE3C9;--ink:#1A2233;--ink-soft:#5B6472;--muted:#8A93A1;--bg:#F5F6F8;--card:#FFFFFF;--line:#E7E9EE;--amber:#A66A0A;--amber-tint:#FBEFDA;--slate:#5B6472;--slate-tint:#EEF0F3;--danger:#9A3B3B}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);font-family:'Inter','Segoe UI',sans-serif;color:var(--ink)}
  .page{max-width:1180px;margin:0 auto;padding:32px 24px 64px}
  .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;flex-wrap:wrap;gap:12px}
  .breadcrumb{font-size:13px;color:var(--muted)}
  .breadcrumb a{color:var(--ink);text-decoration:none;font-weight:500}
  .breadcrumb a:hover{text-decoration:underline}
  .breadcrumb strong{color:var(--ink);font-weight:600}
  .actions{display:flex;gap:10px;flex-wrap:wrap}
  .btn{display:flex;align-items:center;gap:7px;font-size:13px;font-weight:600;padding:9px 14px;border-radius:8px;cursor:pointer;border:1px solid var(--line);background:#fff;color:var(--ink-soft);transition:background .15s,border-color .15s}
  .btn svg{width:15px;height:15px}
  .btn:hover{background:#FAFAFB}
  .btn.primary{background:var(--green);border-color:var(--green);color:#fff}
  .btn.primary:hover{background:#0B6E2A}
  .btn.danger-outline{color:var(--danger)}
  /* Dialog (sd-btn) button style */
  .sd-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:36px;padding:0 16px;border-radius:6px;border:1px solid transparent;font-family:'Inter','Segoe UI',sans-serif;font-size:14px;font-weight:500;line-height:1;white-space:nowrap;cursor:pointer;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);transition:background-color .15s ease,border-color .15s ease,color .15s ease;text-decoration:none}
  .sd-btn svg{width:15px;height:15px}
  .sd-btn-default{background:#18181b;color:#fafafa}
  .sd-btn-default:hover{background:#27272a}
  .sd-btn-outline{background:#ffffff;border-color:#e4e4e7;color:#18181b}
  .sd-btn-outline:hover{background:#f4f4f5}
  .sd-btn-danger{background:#ffffff;border-color:#fecaca;color:#b91c1c}
  .sd-btn-danger:hover{background:#fef2f2}
  .profile-header{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:28px;display:flex;gap:28px;margin-bottom:22px;box-shadow:0 1px 2px rgba(16,24,40,0.03)}
  .profile-photo{width:190px;height:190px;border-radius:12px;overflow:hidden;flex-shrink:0;background:var(--green-tint);display:flex;align-items:center;justify-content:center}
  .profile-photo img{width:100%;height:100%;object-fit:cover}
  .profile-photo .initials{font-size:48px;font-weight:700;color:var(--green)}
  .profile-main{flex:1;min-width:0}
  .name-row{display:flex;align-items:center;gap:12px;margin-bottom:6px;flex-wrap:wrap}
  .pet-name{font-size:28px;font-weight:700;margin:0}
  .badge{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:4px;font-family:'Inter',sans-serif;font-size:12px;font-weight:500;line-height:1.4;border:1px solid transparent;white-space:nowrap}
  .badge.vaccinated{background:var(--green-tint);color:var(--green);border-color:#a8d5b4}
  .badge.unvaccinated{background:var(--amber-tint);color:var(--amber);border-color:#e8cf9a}
  .badge.pending{background:var(--slate-tint);color:var(--slate);border-color:#d5d9e0}
  .badge .dot{width:6px;height:6px;border-radius:50%;background:currentColor}
  .pet-id{font-size:12.5px;color:var(--muted);margin-bottom:14px}
  .summary-line{display:flex;flex-wrap:wrap;gap:6px 0;font-size:13.5px;color:var(--ink-soft)}
  .summary-line span:not(:last-child)::after{content:"\2022";margin:0 10px;color:var(--line)}
  .layout{display:grid;grid-template-columns:2fr 1fr;gap:22px;align-items:start}
  .card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:22px 24px;margin-bottom:22px;box-shadow:0 1px 2px rgba(16,24,40,0.03)}
  .card-title{font-size:15px;font-weight:700;margin:0 0 4px}
  .card-subtitle{font-size:12.5px;color:var(--muted);margin:0 0 18px}
  .field-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px 20px}
  .field .label{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:600;margin-bottom:4px}
  .field .value{font-size:14px;color:var(--ink);font-weight:500}
  .field .value.empty{color:var(--muted);font-weight:400;font-style:italic}
  .empty-state{text-align:center;padding:28px 20px;color:var(--muted);font-size:13.5px}
  .empty-state svg{width:30px;height:30px;color:#C7CCD4;margin-bottom:10px}
  .timeline{display:flex;flex-direction:column}
  .timeline-item{display:flex;gap:14px;padding-bottom:20px;position:relative}
  .timeline-item:not(:last-child)::before{content:"";position:absolute;left:6px;top:18px;bottom:-2px;width:1px;background:var(--line)}
  .timeline-dot{width:13px;height:13px;border-radius:50%;background:var(--green-tint);border:2px solid var(--green);flex-shrink:0;margin-top:3px}
  .timeline-body{flex:1}
  .timeline-top{display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-bottom:3px}
  .timeline-event{font-size:13.5px;font-weight:600;color:var(--ink)}
  .timeline-date{font-size:12px;color:var(--muted);white-space:nowrap}
  .timeline-status{font-size:12px;color:var(--ink-soft)}
  .status-tag{display:inline-flex;align-items:center;padding:2px 8px;border-radius:4px;font-family:'Inter',sans-serif;font-size:11px;font-weight:500;line-height:1.4;border:1px solid transparent;white-space:nowrap}
  .status-tag.registration{background:var(--slate-tint);color:var(--slate);border-color:#d5d9e0}
  .status-tag.completed{background:var(--green-tint);color:var(--green);border-color:#a8d5b4}
  .status-tag.scheduled{background:#eff6ff;color:#1e40af;border-color:#bfdbfe}
  .doc-list{display:flex;flex-direction:column;gap:10px}
  .doc-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;text-decoration:none;color:var(--ink)}
  .doc-item svg{width:16px;height:16px;color:var(--muted)}
  .appointment-card{background:var(--green-tint);border:1px solid var(--green-line);border-radius:14px;padding:20px;margin-bottom:22px}
  .appointment-card .card-title{color:#0B5F25}
  .appt-row{display:flex;justify-content:space-between;font-size:13px;padding:7px 0;border-bottom:1px solid rgba(14,129,49,0.12)}
  .appt-row:last-child{border-bottom:none}
  .appt-row .k{color:#3E6B4C}
  .appt-row .v{font-weight:600;color:#0B5F25}
  .appt-status{display:inline-block;margin-top:12px;font-size:11.5px;font-weight:700;color:#fff;background:var(--green);padding:5px 11px;border-radius:999px}
  .pet-edit-modal{position:fixed;inset:0;z-index:1000;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.8)}
  .pet-edit-modal.open{display:flex}
  .pet-edit-dialog{position:relative;width:min(560px,100%);max-height:calc(100vh - 32px);overflow-y:auto;background:#fff;border:1px solid #e4e4e7;border-radius:8px;padding:24px;box-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1)}
  .pet-edit-dialog h2{margin:0;font-size:18px;font-weight:600;color:#18181b}
  .pet-edit-dialog p{margin:6px 0 18px;font-size:14px;color:#71717a}
  .pet-edit-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
  .pet-edit-field{display:flex;flex-direction:column;gap:6px}
  .pet-edit-field.full{grid-column:1/-1}
  .pet-edit-field label{font-size:14px;font-weight:500;color:#18181b}
  .pet-edit-field input,.pet-edit-field select{width:100%;height:36px;padding:0 12px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font:14px Inter,'Segoe UI',sans-serif;box-sizing:border-box}
  .pet-edit-field input:focus,.pet-edit-field select:focus{outline:none;border-color:#18181b;box-shadow:0 0 0 1px #18181b}
  .pet-edit-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:20px}
  .pet-edit-close{position:absolute;top:16px;right:16px;width:24px;height:24px;border:0;border-radius:4px;background:transparent;color:#71717a;cursor:pointer}
  .pet-edit-close:hover{background:#f4f4f5;color:#18181b}
  @media(max-width:560px){.pet-edit-grid{grid-template-columns:1fr}.pet-edit-field.full{grid-column:auto}.pet-edit-actions{flex-direction:column-reverse}.pet-edit-actions button{width:100%}}
  @media(max-width:880px){.layout{grid-template-columns:1fr}.profile-header{flex-direction:column}.field-grid{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<div class="page">
  <div class="topbar">
    <div class="breadcrumb"><a href="dashboard-resident.php#my-pets">Pet Records</a> / <strong><?php echo htmlspecialchars($pet['pet_name']); ?></strong></div>
    <div class="actions">
      <?php if ($isStaff || $isAdmin): ?><button class="sd-btn sd-btn-default" type="button" onclick="openPetEditDialog()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>Edit Pet Profile</button><?php endif; ?>
      <button class="sd-btn sd-btn-outline" onclick="window.print()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V4h12v5M6 18h12v3H6zM4 9h16v6H4z"/></svg>Print Pet Profile</button>
      <?php if ($isStaff || $isAdmin): ?>
        <button class="sd-btn sd-btn-outline" onclick="window.location.href='dashboard-staff.php#client-pets'"><i class="fas fa-arrow-left"></i>Back to All Clients</button>
      <?php else: ?>
        <button class="sd-btn sd-btn-outline" onclick="window.location.href='dashboard-resident.php#my-pets'"><i class="fas fa-arrow-left"></i>Back to My Pets</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="profile-header">
    <div class="profile-photo"><?php if (!empty($pet['photo_url'])): ?><img src="<?php echo htmlspecialchars($pet['photo_url']); ?>" alt="<?php echo htmlspecialchars($pet['pet_name']); ?>"><?php else: ?><span class="initials"><?php echo strtoupper(substr($pet['pet_name'], 0, 1)); ?></span><?php endif; ?></div>
    <div class="profile-main">
      <div class="name-row">
        <h1 class="pet-name"><?php echo htmlspecialchars($pet['pet_name']); ?></h1>
        <span class="badge <?php echo $badgeClass; ?>"><span class="dot"></span><?php echo $badgeText; ?></span>
      </div>
      <div class="pet-id">Pet ID: <?php echo htmlspecialchars($pet['reference_id'] ?? '#PET-' . str_pad($pet['pet_id'], 5, '0', STR_PAD_LEFT)); ?></div>
      <div class="summary-line">
        <span><?php echo ucfirst($pet['pet_type']); ?></span>
        <span><?php echo htmlspecialchars($pet['breed'] ?? '—'); ?></span>
        <span><?php echo ucfirst($pet['gender'] ?? '—'); ?></span>
        <span><?php echo $ageDisplay; ?></span>
        <span><?php echo htmlspecialchars($pet['color'] ?? '—'); ?></span>
        <span>Registered <?php echo date('M d, Y', strtotime($pet['created_at'])); ?></span>
      </div>
    </div>
  </div>

  <div class="layout">
    <div>
      <div class="card">
        <h2 class="card-title">Complete Profile</h2>
        <p class="card-subtitle">Registered pet information on file</p>
        <div class="field-grid">
          <div class="field"><div class="label">Species</div><div class="value"><?php echo ucfirst($pet['pet_type']); ?></div></div>
          <div class="field"><div class="label">Breed</div><div class="value"><?php echo htmlspecialchars($pet['breed'] ?? '—'); ?></div></div>
          <div class="field"><div class="label">Sex</div><div class="value"><?php echo ucfirst($pet['gender'] ?? '—'); ?></div></div>
          <div class="field"><div class="label">Age</div><div class="value"><?php echo $ageDisplay; ?></div></div>
          <div class="field"><div class="label">Date of Birth</div><div class="value"><?php echo !empty($pet['date_of_birth']) ? date('M d, Y', strtotime($pet['date_of_birth'])) : '—'; ?></div></div>
          <div class="field"><div class="label">Color / Markings</div><div class="value"><?php echo htmlspecialchars($pet['color'] ?? '—'); ?></div></div>
          <div class="field"><div class="label">Weight</div><div class="value"><?php echo !empty($pet['weight']) ? $pet['weight'] . ' kg' : '—'; ?></div></div>
          <div class="field"><div class="label">Microchip Number</div><div class="value empty"><?php echo htmlspecialchars($pet['microchip_id'] ?? 'Not yet implemented'); ?></div></div>
          <div class="field"><div class="label">Vaccination Status</div><div class="value"><?php echo htmlspecialchars($vaxStatus ?: 'Not recorded'); ?></div></div>
          <div class="field"><div class="label">Registration Date</div><div class="value"><?php echo date('M d, Y', strtotime($pet['created_at'])); ?></div></div>
          <div class="field"><div class="label">Owner Relationship</div><div class="value">Primary Owner<?php if (!empty($pet['from_adoption'])) echo ' (Adopted)'; ?></div></div>
        </div>
      </div>

      <div class="card">
        <h2 class="card-title">Owner Information</h2>
        <p class="card-subtitle">Read-only — registered under resident account</p>
        <div class="field-grid">
          <div class="field"><div class="label">Full Name</div><div class="value"><?php echo htmlspecialchars($pet['owner_name']); ?></div></div>
          <div class="field"><div class="label">Email Address</div><div class="value"><?php echo htmlspecialchars($pet['owner_email']); ?></div></div>
          <div class="field"><div class="label">Contact Number</div><div class="value"><?php echo htmlspecialchars($pet['owner_phone'] ?? '—'); ?></div></div>
          <div class="field"><div class="label">Home Address</div><div class="value"><?php echo htmlspecialchars($pet['owner_address'] ?? '—'); ?></div></div>
          <div class="field"><div class="label">Barangay</div><div class="value"><?php echo htmlspecialchars($pet['barangay_name'] ?? '—'); ?></div></div>
        </div>
      </div>

      <div class="card">
        <h2 class="card-title">Medical Summary</h2>
        <p class="card-subtitle">Vaccination, treatment, and health records</p>
        <div class="field-grid" style="margin-bottom:18px;">
          <div class="field"><div class="label">Vaccination Status</div><div class="value"><?php echo htmlspecialchars($vaxStatus ?: 'Not recorded'); ?></div></div>
          <div class="field"><div class="label">Sterilization Status</div><div class="value <?php echo empty($pet['sterilization_status']) ? 'empty' : ''; ?>"><?php echo !empty($pet['sterilization_status']) && $pet['sterilization_status'] === 'sterilized' ? 'Sterilized' : 'Not recorded'; ?></div></div>
          <div class="field"><div class="label">Allergies</div><div class="value <?php echo empty($pet['allergies']) ? 'empty' : ''; ?>"><?php echo htmlspecialchars($pet['allergies'] ?? 'None recorded'); ?></div></div>
          <div class="field"><div class="label">Current Medications</div><div class="value <?php echo empty($pet['current_medications']) ? 'empty' : ''; ?>"><?php echo htmlspecialchars($pet['current_medications'] ?? 'None recorded'); ?></div></div>
        </div>
        <div class="empty-state">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 12h6M9 16h6M9 8h6M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/></svg>
          <div>No medical records available yet.</div>
        </div>
      </div>
    </div>

    <div>
      <div class="card">
        <h2 class="card-title">Service History</h2>
        <p class="card-subtitle">Chronological record of all pet-related interactions</p>
        <?php if (empty($timeline)): ?><div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 12h6M9 16h6M9 8h6M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/></svg><div>No activity recorded yet.</div></div>
        <?php else: ?><div class="timeline"><?php foreach ($timeline as $entry): ?><div class="timeline-item"><div class="timeline-dot"></div><div class="timeline-body"><div class="timeline-top"><span class="timeline-event"><?php echo htmlspecialchars($entry['event']); ?></span><span class="timeline-date"><?php echo date('M d, Y', strtotime($entry['date'])); ?></span></div><div class="timeline-status"><?php echo htmlspecialchars($entry['details']); ?>           <span class="status-tag <?php echo htmlspecialchars($entry['type']); ?>"><?php echo ucfirst($entry['type']); ?></span></div></div></div><?php endforeach; ?></div><?php endif; ?>
      </div>

      <div class="card">
        <h2 class="card-title">Documents &amp; Attachments</h2>
        <p class="card-subtitle">Vaccination certificates, lab results, and clearances</p>
        <?php if (empty($documents)): ?><div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg><div>No documents are currently available.</div></div>
        <?php else: ?><div class="doc-list"><?php foreach ($documents as $doc): ?><a href="<?php echo htmlspecialchars($doc['file_url']); ?>" class="doc-item" target="_blank"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg><?php echo htmlspecialchars($doc['file_name']); ?></a><?php endforeach; ?></div><?php endif; ?>
      </div>

      <?php if ($upcomingAppt): ?>
      <?php $typeLabels=['vaccination'=>'Annual Vaccination Booster','checkup'=>'Wellness Checkup','kapon'=>'Kapon (Spay/Neuter)','adoption'=>'Adoption Appointment']; $apptType=$typeLabels[$upcomingAppt['appointment_type']]??ucfirst($upcomingAppt['appointment_type']); ?>
      <div class="appointment-card">
        <h2 class="card-title">Upcoming Appointment</h2>
        <div class="appt-row"><span class="k">Service</span><span class="v"><?php echo $apptType; ?></span></div>
        <div class="appt-row"><span class="k">Date</span><span class="v"><?php echo date('M d, Y', strtotime($upcomingAppt['preferred_date'])); ?></span></div>
        <div class="appt-row"><span class="k">Time</span><span class="v"><?php echo date('g:i A', strtotime($upcomingAppt['preferred_time'])); ?></span></div>
        <span class="appt-status"><?php echo ucfirst($upcomingAppt['status']); ?></span>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if ($isStaff || $isAdmin): ?>
<div class="pet-edit-modal" id="petEditModal" role="dialog" aria-modal="true" aria-labelledby="petEditTitle">
  <form class="pet-edit-dialog" action="edit-pet.php" method="POST" enctype="multipart/form-data">
    <button type="button" class="pet-edit-close" onclick="closePetEditDialog()" aria-label="Close">&times;</button>
    <h2 id="petEditTitle">Edit Pet Profile</h2>
    <p>Update the registered pet information below.</p>
    <input type="hidden" name="pet_id" value="<?php echo (int)$pet['pet_id']; ?>">
    <input type="hidden" name="return_to" value="pet-profile">
    <div class="pet-edit-grid">
      <div class="pet-edit-field"><label for="editPetName">Pet Name</label><input id="editPetName" name="pet_name" value="<?php echo htmlspecialchars($pet['pet_name']); ?>" required></div>
      <div class="pet-edit-field"><label for="editPetSpecies">Species</label><select id="editPetSpecies" name="species" required><option value="dog" <?php echo strtolower($pet['pet_type']) === 'dog' ? 'selected' : ''; ?>>Dog</option><option value="cat" <?php echo strtolower($pet['pet_type']) === 'cat' ? 'selected' : ''; ?>>Cat</option><option value="other" <?php echo !in_array(strtolower($pet['pet_type']), ['dog','cat'], true) ? 'selected' : ''; ?>>Other</option></select></div>
      <div class="pet-edit-field"><label for="editPetBreed">Breed</label><input id="editPetBreed" name="breed" value="<?php echo htmlspecialchars($pet['breed'] ?? ''); ?>"></div>
      <div class="pet-edit-field"><label for="editPetGender">Gender</label><select id="editPetGender" name="gender" required><option value="male" <?php echo strtolower($pet['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option><option value="female" <?php echo strtolower($pet['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option></select></div>
      <div class="pet-edit-field"><label for="editPetAge">Age (years)</label><input id="editPetAge" type="number" min="0" name="age" value="<?php echo (int)$pet['age']; ?>"></div>
      <div class="pet-edit-field"><label for="editPetWeight">Weight (kg)</label><input id="editPetWeight" type="number" step="0.1" min="0" name="weight" value="<?php echo htmlspecialchars((string)$pet['weight']); ?>"></div>
      <div class="pet-edit-field full"><label for="editPetColor">Color / Markings</label><input id="editPetColor" name="color" value="<?php echo htmlspecialchars($pet['color'] ?? ''); ?>"></div>
      <div class="pet-edit-field full"><label for="editPetVaccinated">Vaccination Status</label><select id="editPetVaccinated" name="vaccinated"><option value="yes" <?php echo stripos($vaxStatus, 'fully') !== false ? 'selected' : ''; ?>>Vaccinated</option><option value="no" <?php echo stripos($vaxStatus, 'not') !== false ? 'selected' : ''; ?>>Not Vaccinated</option><option value="partial" <?php echo stripos($vaxStatus, 'partial') !== false ? 'selected' : ''; ?>>Partially Vaccinated</option></select></div>
      <div class="pet-edit-field full"><label for="editPetPhoto">Replace Photo (optional)</label><input id="editPetPhoto" type="file" name="photo" accept="image/jpeg,image/png,image/gif,image/webp"></div>
    </div>
    <div class="pet-edit-actions"><button type="button" class="sd-btn sd-btn-outline" onclick="closePetEditDialog()">Cancel</button><button type="submit" class="sd-btn sd-btn-default">Save Changes</button></div>
  </form>
</div>
<script>
function openPetEditDialog(){ document.getElementById('petEditModal').classList.add('open'); }
function closePetEditDialog(){ document.getElementById('petEditModal').classList.remove('open'); }
document.getElementById('petEditModal').addEventListener('click', function(e){ if(e.target === this) closePetEditDialog(); });
</script>
<?php endif; ?>
</body>
</html>
