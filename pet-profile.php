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

require_once 'db.php';

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
    'details' => 'Registered by ' . htmlspecialchars($pet['owner_name']) . ' â€” ' . htmlspecialchars($pet['barangay_name'] ?? 'N/A'),
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
  :root{--bg:#f5f5f5;--card:#fff;--border:#e4e4e7;--border-light:#f4f4f5;--ink:#18181b;--ink-soft:#52525b;--muted:#71717a;--placeholder:#a1a1aa;--green:#045e1f;--green-tint:#f0fdf4;--green-border:#bbf7d0;--amber:#92400e;--amber-tint:#fef3c7;--red:#991b1b;--red-tint:#fee2e2;--blue:#1e40af;--blue-tint:#eff6ff}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);font-family:'Inter','Segoe UI',sans-serif;color:var(--ink);font-size:14px;line-height:1.5}
  .topbar{display:flex;align-items:center;justify-content:space-between;padding:16px 32px;background:#fff;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:10}
  .breadcrumb{font-size:13px;color:var(--muted)}
  .breadcrumb a{color:var(--ink);text-decoration:none;font-weight:500}
  .breadcrumb a:hover{text-decoration:underline}
  .breadcrumb strong{color:var(--ink);font-weight:600}
  .topbar-actions{display:flex;gap:8px}
  .btn{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:500;padding:8px 14px;border-radius:6px;cursor:pointer;border:1px solid var(--border);background:#fff;color:var(--ink-soft);transition:background .15s,border-color .15s;text-decoration:none;font-family:inherit}
  .btn:hover{background:#f4f4f5}
  .btn-primary{background:var(--ink);color:#fafafa;border-color:var(--ink)}
  .btn-primary:hover{background:#27272a}
  .btn i,.btn svg{font-size:13px}
  .record-layout{display:grid;grid-template-columns:300px 1fr 300px;gap:0;min-height:calc(100vh - 57px)}
  .record-sidebar{background:#fff;border-right:1px solid var(--border);padding:28px 24px;position:sticky;top:57px;height:calc(100vh - 57px);overflow-y:auto}
  .sidebar-avatar{width:100%;aspect-ratio:1;border-radius:12px;overflow:hidden;background:#f4f4f5;display:flex;align-items:center;justify-content:center;margin-bottom:20px;border:1px solid var(--border)}
  .sidebar-avatar img{width:100%;height:100%;object-fit:cover}
  .sidebar-avatar .initials{font-size:56px;font-weight:700;color:var(--muted)}
  .sidebar-name{font-size:20px;font-weight:700;color:var(--ink);margin-bottom:4px;line-height:1.2}
  .sidebar-id{font-size:13px;color:var(--muted);margin-bottom:12px}
  .sidebar-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:600;margin-bottom:24px}
  .sidebar-badge.vaccinated{background:var(--green-tint);color:var(--green);border:1px solid var(--green-border)}
  .sidebar-badge.pending{background:var(--amber-tint);color:var(--amber);border:1px solid #fde68a}
  .sidebar-badge.unvaccinated{background:var(--red-tint);color:var(--red);border:1px solid #fecaca}
  .sidebar-badge .dot{width:6px;height:6px;border-radius:50%;background:currentColor}
  .sidebar-section{margin-bottom:24px}
  .sidebar-section-title{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:var(--muted);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--border)}
  .sidebar-field{display:flex;flex-direction:column;gap:3px;margin-bottom:14px}
  .sidebar-field:last-child{margin-bottom:0}
  .sidebar-field .label{font-size:12px;font-weight:500;color:var(--muted)}
  .sidebar-field .value{font-size:14px;font-weight:500;color:var(--ink)}
  .sidebar-field .value.empty{color:var(--placeholder);font-style:italic}
  .sidebar-actions{display:flex;flex-direction:column;gap:8px;margin-top:24px;padding-top:20px;border-top:1px solid var(--border)}
  .sidebar-actions .btn{width:100%;justify-content:center}
  .record-center{padding:28px 32px;overflow-y:auto}
  .center-section{margin-bottom:28px}
  .center-section:last-child{margin-bottom:0}
  .section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
  .section-title{font-size:15px;font-weight:600;color:var(--ink)}
  .section-subtitle{font-size:13px;color:var(--muted);margin-top:2px}
  .timeline{position:relative;padding-left:24px}
  .timeline::before{content:'';position:absolute;left:7px;top:4px;bottom:4px;width:2px;background:var(--border);border-radius:1px}
  .timeline-item{position:relative;padding-bottom:20px}
  .timeline-item:last-child{padding-bottom:0}
  .timeline-dot{position:absolute;left:-20px;top:4px;width:12px;height:12px;border-radius:50%;background:#fff;border:2px solid var(--border);z-index:1}
  .timeline-dot.completed{border-color:var(--green);background:var(--green-tint)}
  .timeline-dot.scheduled{border-color:#2563eb;background:#eff6ff}
  .timeline-dot.registration{border-color:var(--muted);background:#f4f4f5}
  .timeline-event{font-size:14px;font-weight:600;color:var(--ink)}
  .timeline-date{font-size:12px;color:var(--muted);margin-left:auto}
  .timeline-top{display:flex;align-items:center;gap:8px;margin-bottom:4px}
  .timeline-details{font-size:13px;color:var(--ink-soft)}
  .timeline-tag{display:inline-flex;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;margin-top:4px}
  .timeline-tag.completed{background:var(--green-tint);color:var(--green)}
  .timeline-tag.registered{background:#f4f4f5;color:var(--muted)}
  .timeline-tag.scheduled{background:var(--blue-tint);color:var(--blue)}
  .doc-list{display:flex;flex-direction:column;gap:6px}
  .doc-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--ink);transition:background .15s}
  .doc-item:hover{background:#f9fafb}
  .doc-item svg{width:18px;height:18px;color:var(--muted);flex-shrink:0}
  .doc-item span{font-size:13px;font-weight:500}
  .doc-item .doc-type{margin-left:auto;font-size:12px;color:var(--muted);font-weight:400}
  .empty-state{text-align:center;padding:32px 16px;color:var(--muted)}
  .empty-state svg{width:40px;height:40px;margin-bottom:10px;color:var(--placeholder)}
  .empty-state div{font-size:13px}
  .record-right{background:#fff;border-left:1px solid var(--border);padding:28px 24px;position:sticky;top:57px;height:calc(100vh - 57px);overflow-y:auto}
  .owner-card{display:flex;align-items:center;gap:12px;margin-bottom:20px;padding-bottom:20px;border-bottom:1px solid var(--border)}
  .owner-avatar{width:44px;height:44px;border-radius:50%;background:#f4f4f5;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:600;color:var(--muted);flex-shrink:0}
  .owner-name{font-size:14px;font-weight:600;color:var(--ink)}
  .owner-label{font-size:12px;color:var(--muted)}
  .upcoming-card{background:var(--green-tint);border:1px solid var(--green-border);border-radius:8px;padding:16px;margin-bottom:24px}
  .upcoming-title{font-size:13px;font-weight:600;color:var(--green);margin-bottom:12px;display:flex;align-items:center;gap:6px}
  .upcoming-row{display:flex;justify-content:space-between;padding:6px 0;font-size:13px}
  .upcoming-row .k{color:var(--muted)}
  .upcoming-row .v{font-weight:500;color:var(--ink)}
  .upcoming-status{display:inline-flex;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600;margin-top:8px;background:#fff;color:var(--green);border:1px solid var(--green-border)}
  .quick-info-grid{display:grid;grid-template-columns:1fr;gap:16px}
  .quick-stat{display:flex;flex-direction:column;gap:2px}
  .quick-stat .label{font-size:12px;font-weight:500;color:var(--muted)}
  .quick-stat .value{font-size:16px;font-weight:700;color:var(--ink)}
  @media(max-width:1200px){.record-layout{grid-template-columns:280px 1fr}.record-right{display:none}}
  @media(max-width:768px){.record-layout{grid-template-columns:1fr}.record-sidebar{position:static;height:auto}.record-right{display:none}}
</style>
</head>
<body>
<div class="topbar">
  <div class="breadcrumb"><a href="dashboard-resident.php#my-pets">My Pets</a> / <strong><?php echo htmlspecialchars($pet['pet_name']); ?></strong></div>
  <div class="topbar-actions">
    <button class="btn" onclick="window.location.href='dashboard-resident.php#my-pets'"><i class="fas fa-arrow-left"></i> Back to My Pets</button>
    <?php if ($isStaff || $isAdmin): ?><button class="btn btn-primary" onclick="alert('Edit coming soon.')"><i class="fas fa-edit"></i> Edit Pet</button><?php endif; ?>
  </div>
</div>
<div class="record-layout">
  <aside class="record-sidebar">
    <div class="sidebar-avatar"><?php if (!empty($pet['photo_url'])): ?><img src="<?php echo htmlspecialchars($pet['photo_url']); ?>" alt="<?php echo htmlspecialchars($pet['pet_name']); ?>"><?php else: ?><span class="initials"><?php echo strtoupper(substr($pet['pet_name'], 0, 1)); ?></span><?php endif; ?></div>
    <h1 class="sidebar-name"><?php echo htmlspecialchars($pet['pet_name']); ?></h1>
    <div class="sidebar-id"><?php echo htmlspecialchars($pet['reference_id'] ?? '#PET-' . str_pad($pet['pet_id'], 5, '0', STR_PAD_LEFT)); ?></div>
    <div class="sidebar-badge <?php echo $badgeClass; ?>"><span class="dot"></span><?php echo $badgeText; ?></div>
    <div class="sidebar-section">
      <div class="sidebar-section-title">Pet Details</div>
      <div class="sidebar-field"><div class="label">Species</div><div class="value"><?php echo ucfirst($pet['pet_type']); ?></div></div>
      <div class="sidebar-field"><div class="label">Breed</div><div class="value"><?php echo htmlspecialchars($pet['breed'] ?? '—'); ?></div></div>
      <div class="sidebar-field"><div class="label">Sex</div><div class="value"><?php echo ucfirst($pet['gender'] ?? '—'); ?></div></div>
      <div class="sidebar-field"><div class="label">Age</div><div class="value"><?php echo $ageDisplay; ?></div></div>
      <div class="sidebar-field"><div class="label">Date of Birth</div><div class="value <?php echo empty($pet['date_of_birth']) ? 'empty' : ''; ?>"><?php echo !empty($pet['date_of_birth']) ? date('M d, Y', strtotime($pet['date_of_birth'])) : 'Not recorded'; ?></div></div>
      <div class="sidebar-field"><div class="label">Color / Markings</div><div class="value"><?php echo htmlspecialchars($pet['color'] ?? '—'); ?></div></div>
      <div class="sidebar-field"><div class="label">Weight</div><div class="value <?php echo empty($pet['weight']) ? 'empty' : ''; ?>"><?php echo !empty($pet['weight']) ? $pet['weight'] . ' kg' : 'Not recorded'; ?></div></div>
      <div class="sidebar-field"><div class="label">Microchip</div><div class="value <?php echo empty($pet['microchip_id']) ? 'empty' : ''; ?>"><?php echo htmlspecialchars($pet['microchip_id'] ?? 'Not yet implemented'); ?></div></div>
    </div>
    <div class="sidebar-section">
      <div class="sidebar-section-title">Health</div>
      <div class="sidebar-field"><div class="label">Vaccination</div><div class="value"><?php echo htmlspecialchars($vaxStatus ?: 'Not recorded'); ?></div></div>
      <div class="sidebar-field"><div class="label">Sterilization</div><div class="value"><?php echo !empty($pet['sterilization_status']) && $pet['sterilization_status'] === 'sterilized' ? 'Sterilized' : 'Not recorded'; ?></div></div>
      <div class="sidebar-field"><div class="label">Allergies</div><div class="value <?php echo empty($pet['allergies']) ? 'empty' : ''; ?>"><?php echo htmlspecialchars($pet['allergies'] ?? 'None recorded'); ?></div></div>
      <div class="sidebar-field"><div class="label">Medications</div><div class="value <?php echo empty($pet['current_medications']) ? 'empty' : ''; ?>"><?php echo htmlspecialchars($pet['current_medications'] ?? 'None recorded'); ?></div></div>
    </div>
    <div class="sidebar-section">
      <div class="sidebar-section-title">Registration</div>
      <div class="sidebar-field"><div class="label">Registered</div><div class="value"><?php echo date('M d, Y', strtotime($pet['created_at'])); ?></div></div>
      <div class="sidebar-field"><div class="label">Category</div><div class="value"><?php echo $pet['from_adoption'] ? 'Adopted' : 'Owned'; ?></div></div>
    </div>
    <div class="sidebar-actions"><button class="btn" onclick="window.location.href='dashboard-resident.php#my-pets'"><i class="fas fa-arrow-left"></i> Back to List</button></div>
  </aside>
  <main class="record-center">
    <div class="center-section">
      <div class="section-header"><div><div class="section-title">Activity</div><div class="section-subtitle">Service history and registration events</div></div></div>
      <?php if (empty($timeline)): ?><div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 12h6M9 16h6M9 8h6M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/></svg><div>No activity recorded yet.</div></div>
      <?php else: ?><div class="timeline"><?php foreach ($timeline as $entry): ?><div class="timeline-item"><div class="timeline-dot <?php echo $entry['type']; ?>"></div><div class="timeline-top"><span class="timeline-event"><?php echo htmlspecialchars($entry['event']); ?></span><span class="timeline-date"><?php echo date('M d, Y', strtotime($entry['date'])); ?></span></div><div class="timeline-details"><?php echo htmlspecialchars($entry['details']); ?></div><span class="timeline-tag <?php echo $entry['type']; ?>"><?php echo ucfirst($entry['type']); ?></span></div><?php endforeach; ?></div><?php endif; ?>
    </div>
    <div class="center-section">
      <div class="section-header"><div><div class="section-title">Documents &amp; Attachments</div><div class="section-subtitle">Vaccination certificates, lab results, and clearances</div></div></div>
      <?php if (empty($documents)): ?><div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg><div>No documents are currently available.</div></div>
      <?php else: ?><div class="doc-list"><?php foreach ($documents as $doc): ?><a href="<?php echo htmlspecialchars($doc['file_url']); ?>" class="doc-item" target="_blank"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg><span><?php echo htmlspecialchars($doc['file_name']); ?></span><span class="doc-type"><?php echo ucfirst(str_replace('_', ' ', $doc['document_type'])); ?></span></a><?php endforeach; ?></div><?php endif; ?>
      <?php if ($isStaff || $isAdmin): ?><div style="margin-top:12px;text-align:right;"><button class="btn" onclick="alert('Document upload coming soon.')"><i class="fas fa-upload"></i> Upload Document</button></div><?php endif; ?>
    </div>
  </main>
  <aside class="record-right">
    <div class="owner-card"><div class="owner-avatar"><?php echo strtoupper(substr($pet['owner_name'], 0, 1)); ?></div><div><div class="owner-name"><?php echo htmlspecialchars($pet['owner_name']); ?></div><div class="owner-label">Pet Owner</div></div></div>
    <div class="sidebar-section">
      <div class="sidebar-section-title">Owner Information</div>
      <div class="sidebar-field"><div class="label">Full Name</div><div class="value"><?php echo htmlspecialchars($pet['owner_name']); ?></div></div>
      <div class="sidebar-field"><div class="label">Email</div><div class="value"><?php echo htmlspecialchars($pet['owner_email']); ?></div></div>
      <div class="sidebar-field"><div class="label">Phone</div><div class="value"><?php echo htmlspecialchars($pet['owner_phone'] ?? '—'); ?></div></div>
      <div class="sidebar-field"><div class="label">Address</div><div class="value"><?php echo htmlspecialchars($pet['owner_address'] ?? '—'); ?></div></div>
      <div class="sidebar-field"><div class="label">Barangay</div><div class="value"><?php echo htmlspecialchars($pet['barangay_name'] ?? '—'); ?></div></div>
      <div class="sidebar-field"><div class="label">Owner Since</div><div class="value"><?php echo date('M d, Y', strtotime($pet['owner_reg_date'])); ?></div></div>
    </div>
    <?php if ($upcomingAppt): ?>
    <?php $typeLabels=['vaccination'=>'Vaccination','checkup'=>'Wellness Checkup','kapon'=>'Kapon (Spay/Neuter)','adoption'=>'Adoption']; $apptType=$typeLabels[$upcomingAppt['appointment_type']]??ucfirst($upcomingAppt['appointment_type']); ?>
    <div class="upcoming-card"><div class="upcoming-title"><i class="fas fa-calendar-check"></i> Upcoming Appointment</div><div class="upcoming-row"><span class="k">Service</span><span class="v"><?php echo $apptType; ?></span></div><div class="upcoming-row"><span class="k">Date</span><span class="v"><?php echo date('M d, Y', strtotime($upcomingAppt['preferred_date'])); ?></span></div><div class="upcoming-row"><span class="k">Time</span><span class="v"><?php echo date('g:i A', strtotime($upcomingAppt['preferred_time'])); ?></span></div><span class="upcoming-status"><?php echo ucfirst($upcomingAppt['status']); ?></span></div>
    <?php endif; ?>
    <div class="sidebar-section"><div class="sidebar-section-title">Quick Stats</div><div class="quick-info-grid"><div class="quick-stat"><div class="label">Total Services</div><div class="value"><?php echo count($timeline); ?></div></div><div class="quick-stat"><div class="label">Documents on File</div><div class="value"><?php echo count($documents); ?></div></div></div></div>
  </aside>
</div>
</body>
</html>