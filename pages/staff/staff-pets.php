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
require_once __DIR__ . '/../../includes/adoption-workflow.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

$pageTitle = 'Pet Listings';
$activePage = 'pet-listings';

adoptionReleaseExpiredReservations($conn);

$listings = [];
$lq = $conn->query(
    "SELECT p.*,
            application.approved_pickup_at AS adoption_approved_pickup_at,
            application.status AS adoption_application_status
     FROM pet_listings p
     LEFT JOIN adoption_applications application
       ON application.application_id = (
           SELECT latest.application_id
           FROM adoption_applications latest
           WHERE latest.pet_listing_id = p.listing_id
           ORDER BY latest.application_date DESC, latest.application_id DESC
           LIMIT 1
       )
     ORDER BY p.created_at DESC"
);
if ($lq) {
    while ($row = $lq->fetch_assoc()) $listings[] = $row;
}

$petLookup = [];
$pq = $conn->query("SELECT pet_name, vaccination_status, owner_id, reference_id FROM pets");
if ($pq) {
    while ($r = $pq->fetch_assoc()) {
        $k = strtolower(trim($r['pet_name']));
        if ($k !== '' && !isset($petLookup[$k])) $petLookup[$k] = $r;
    }
}

$userLookup = [];
$uq = $conn->query("SELECT user_id, reference_id, full_name, address, avatar_url FROM users");
if ($uq) {
    while ($r = $uq->fetch_assoc()) $userLookup[$r['user_id']] = $r;
}

$payload = [];
foreach ($listings as $row) {
    $id = (int)$row['listing_id'];
    $match = $petLookup[strtolower(trim($row['name']))] ?? null;
    $ref = ($match && !empty($match['reference_id']))
        ? $match['reference_id']
        : '#PET-' . strtoupper(substr(md5('lst' . $id), 0, 6));
    $vaccination = ($match && !empty($match['vaccination_status'])) ? $match['vaccination_status'] : 'Not specified';
    $owner = null;
    if ($match && isset($match['owner_id']) && isset($userLookup[$match['owner_id']])) {
        $u = $userLookup[$match['owner_id']];
        $owner = [
            'name'    => $u['full_name'] ?? '—',
            'ref'     => $u['reference_id'] ?? '',
            'address' => $u['address'] ?? '—',
            'avatar'  => $u['avatar_url'] ?? ''
        ];
    }
    $payload[] = [
        'listing_id'   => $id,
        'ref'          => $ref,
        'name'         => $row['name'] ?? '',
        'species'      => $row['species'] ?? '',
        'breed'        => $row['breed'] ?? '',
        'age'          => $row['age'] ?? '',
        'gender'       => $row['gender'] ?? '',
        'weight'       => $row['weight'] ?? '',
        'color'        => $row['color'] ?? '',
        'rescued_date' => $row['rescued_date'] ?? '',
        'description'  => $row['description'] ?? '',
        'photo_url'    => $row['photo_url'] ?? '',
        'status'       => $row['status'] ?? 'available',
        'adopted_at'   => $row['adopted_at'] ?? '',
        'approved_pickup_at' => $row['adoption_approved_pickup_at'] ?? '',
        'adoption_application_status' => $row['adoption_application_status'] ?? '',
        'vaccination'  => $vaccination,
        'owner'        => $owner,
        'created_at'   => $row['created_at'] ?? '',
        'updated_at'   => $row['updated_at'] ?? ''
    ];
}

$success = $_SESSION['pet_success'] ?? null;
$error = $_SESSION['pet_error'] ?? null;
unset($_SESSION['pet_success'], $_SESSION['pet_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($pageTitle); ?> — Staff | DCVS</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/css/dashboard.css" />
  <link rel="stylesheet" href="assets/css/responsive.css" />

  <style>
    /* ── Pet Listings (CPD design system) ── */
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
    .page-title { font-size:1.35rem; font-weight:800; color:#0f172a; margin:0; font-family:'Inter',sans-serif; }
    .page-subtitle { font-size:0.88rem; color:#64748b; margin:4px 0 0; }

    .pets-cms{ padding:0 !important; background:#f8fafc; }
    .reports-fullpage{ background:var(--white); }
    .reports-fullpage .cpd-app{ padding:0; }
    .reports-fullpage .layout{ min-height:calc(100vh - var(--topbar-h)); }
    .reports-fullpage .panel{ background:transparent; border:none; }
    .reports-fullpage .filters{ border-right:1px solid var(--border); }
    .reports-fullpage .db-panel{ border-left:none; border-right:1px solid var(--border); }
    .reports-fullpage .profile-panel{ border-left:none; }

    .cpd .btn{ display:inline-flex; align-items:center; gap:7px; font-family:inherit; font-size:13.5px; font-weight:600; border-radius:10px; padding:10px 16px; border:1px solid var(--border); background:var(--white); color:var(--ink-700); cursor:pointer; transition:background .15s ease, box-shadow .15s ease, border-color .15s ease; white-space:nowrap; }
    .cpd .btn:hover{ background:var(--ink-50); border-color:var(--ink-300); }
    .cpd .btn-primary{ background:var(--green-600); border-color:var(--green-600); color:var(--white); box-shadow:var(--shadow-sm); }
    .cpd .btn-primary:hover{ background:var(--green-700); border-color:var(--green-700); }
    .cpd .btn-sm{ padding:7px 11px; font-size:12.5px; border-radius:8px; }

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
    .cpd .filter-item-left{ display:flex; align-items:center; gap:9px; min-width:0; }
    .cpd .filter-item-left i{ flex-shrink:0; width:14px; font-size:12px; color:#000; }
    .cpd .filter-item.active .filter-item-left i{ color:#000; }
    .cpd .filter-count{ font-size:12px; font-weight:600; color:var(--ink-400); background:var(--ink-100); padding:2px 8px; border-radius:0; }
    .cpd .filter-item.active .filter-count{ background:var(--green-100); color:var(--green-700); }
    .cpd .section-label{ font-size:11px; font-weight:700; letter-spacing:.06em; color:var(--ink-400); text-transform:uppercase; margin:0 0 10px 2px; }
    .cpd select.select-input{ width:100%; padding:10px 12px; border-radius:9px; border:1px solid var(--border); background:var(--white); font-family:inherit; font-size:13.5px; color:var(--ink-700); appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 10px center; cursor:pointer; }
    .cpd .tip-box{ background:var(--green-50); border:1px solid var(--green-100); border-radius:var(--radius-sm); padding:16px; }
    .cpd .tip-title{ display:flex; align-items:center; gap:7px; font-weight:700; font-size:13px; color:var(--green-700); margin-bottom:6px; }
    .cpd .tip-box p{ margin:0; font-size:12.5px; line-height:1.5; color:#3f6b4c; }

    .cpd .add-filters{ display:flex; flex-direction:column; gap:14px; }

    .cpd .sex-seg{ display:flex; gap:4px; background:var(--ink-100); padding:3px; border-radius:9px; }
    .cpd .sex-seg .seg-btn{ flex:1; border:none; background:transparent; font-family:inherit; font-size:12.5px; font-weight:600; color:var(--ink-500); padding:6px 10px; border-radius:7px; cursor:pointer; }
    .cpd .sex-seg .seg-btn.active{ background:var(--white); color:var(--green-700); box-shadow:var(--shadow-sm); }

    .cpd .check-list{ display:flex; flex-direction:column; gap:7px; }
    .cpd .check-row{ display:flex; align-items:center; gap:9px; font-size:13px; font-weight:500; color:var(--ink-700); cursor:pointer; }
    .cpd .check-row input{ width:16px; height:16px; accent-color:var(--green-600); cursor:pointer; margin:0; }

    /* Center db */
    .cpd .db-panel{ padding:18px; border-left:none; border-right:1px solid var(--border); display:flex; flex-direction:column; }
    .cpd .db-panel .kpis{ flex-shrink:0; }
    .cpd .db-panel .db-toolbar{ flex-shrink:0; }
    .cpd .db-table-area{ flex:1; min-height:0; display:flex; flex-direction:column; }
    .cpd .db-panel .pagination{ flex-shrink:0; margin-top:auto; }
    .cpd .db-toolbar{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
    .cpd .db-toolbar .toolbar-actions{ display:flex; align-items:center; gap:8px; flex-wrap:nowrap; }
    .cpd .db-toolbar .btn{ padding:10px 14px; font-size:13.5px; border-radius:10px; gap:7px; }
    .cpd .db-toolbar .btn i{ font-size:12px; }
    .cpd .db-toolbar .btn-primary{ padding:10px 16px; }
    .cpd .db-toolbar .select-input{ padding:10px 30px 10px 12px; font-size:13.5px; border-radius:10px; }
    .cpd .view-controls{ display:flex; align-items:center; gap:4px; background:var(--ink-100); padding:3px; border-radius:9px; }
    .cpd .view-controls .seg-btn{ border:none; background:transparent; font-family:inherit; font-size:12.5px; font-weight:600; color:var(--ink-500); padding:6px 12px; border-radius:7px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
    .cpd .view-controls .seg-btn.active{ background:var(--white); color:var(--green-700); box-shadow:var(--shadow-sm); }
    .cpd .search-grow-wrap{ position:relative; flex:0 1 auto; width:300px; min-width:180px; }
    .cpd .search-grow-wrap input{ width:100%; padding:11px 14px 11px 38px; border-radius:10px; border:1px solid var(--border); font-family:inherit; font-size:13.5px; color:var(--ink-700); outline:none; }
    .cpd .search-grow-wrap input:focus{ border-color:var(--green-500); }
    .cpd .search-grow-wrap input::placeholder{ color:var(--ink-400); }
    .cpd .search-grow-wrap svg{ position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--ink-400); width:15px; height:15px; }
    .cpd .sort-select{ width:auto; min-width:130px; max-width:170px; }
    .cpd table{ width:100%; border-collapse:collapse; }
    .cpd thead th{ text-align:left; font-size:11.5px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; color:#000; padding:0 12px 8px; border-bottom:1px solid var(--border); white-space:nowrap; }
    .cpd tbody tr{ cursor:pointer; transition:background .12s ease; border-bottom:1px solid var(--ink-100); }
    .cpd tbody tr:hover{ background:var(--ink-50); }
    .cpd tbody tr.selected{ background:var(--green-50); }
    .cpd tbody td{ padding:11px 12px; vertical-align:middle; font-size:13.5px; color:var(--ink-700); }
    .cpd .client-name{ font-weight:600; color:var(--ink-900); font-size:13.5px; }
    .cpd .contact-main{ color:var(--ink-700); font-weight:500; }
    .cpd .contact-sub{ font-size:11.5px; color:var(--ink-400); margin-top:1px; }
    .cpd .status-pill{ display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:0; font-size:11.5px; font-weight:700; white-space:nowrap; }
    .cpd .st-dot{ width:6px;height:6px; border-radius:50%; flex-shrink:0; }
    .cpd .row-menu{ width:28px;height:28px; display:inline-flex;align-items:center;justify-content:center; border-radius:7px; color:var(--ink-400); background:none; border:none; cursor:pointer; }
    .cpd .row-menu:hover{ background:var(--ink-100); color:var(--ink-700); }
    .cpd .row-act{ width:30px;height:30px; display:inline-flex;align-items:center;justify-content:center; border-radius:8px; border:1px solid var(--border); background:var(--white); color:var(--ink-500); cursor:pointer; font-size:12.5px; }
    .cpd .row-act:hover{ background:var(--ink-50); color:var(--ink-700); }
    .cpd .row-act.view:hover{ background:var(--green-50); color:var(--green-700); border-color:var(--green-200); }
    .cpd .row-act.edit:hover{ background:#eff6ff; color:#1d4ed8; border-color:#dbeafe; }

    /* Row menu dropdown */
    .cpd .row-menu-pop{ position:absolute; z-index:60; background:var(--white); border:1px solid var(--border); border-radius:10px; box-shadow:var(--shadow-md); padding:6px; min-width:190px; display:none; }
    .cpd .row-menu-pop.open{ display:block; }
    .cpd .row-menu-item{ display:flex; align-items:center; gap:10px; width:100%; padding:9px 10px; border:none; background:none; border-radius:8px; font-family:inherit; font-size:13px; font-weight:500; color:var(--ink-700); cursor:pointer; text-align:left; }
    .cpd .row-menu-item:hover{ background:var(--ink-50); }
    .cpd .row-menu-item.danger{ color:#dc2626; }
    .cpd .row-menu-item.danger:hover{ background:#fef2f2; }
    .cpd .row-menu-item i{ width:15px; text-align:center; }

    .cpd .cards-grid{ display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; align-content:start; }
    .cpd .rpt-card{ background:var(--white); border:1px solid var(--border); border-radius:5px; padding:0; overflow:hidden; cursor:default; display:flex; flex-direction:column; gap:0; }
    .cpd .rpt-card:hover{ }
    .cpd .rpt-card.selected{ }

    .cpd .pagination{ display:flex; align-items:center; justify-content:space-between; margin-top:16px; padding-top:14px; border-top:1px solid var(--border); flex-wrap:wrap; gap:10px; }
    .cpd .pagination-info{ font-size:12.5px; color:var(--ink-500); }
    .cpd .pagination-controls{ display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
    .cpd .page-btn{ min-width:32px;height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); background:var(--white); color:var(--ink-700); font-size:12.5px; font-weight:600; cursor:pointer; display:inline-flex;align-items:center;justify-content:center; }
    .cpd .page-btn:hover{ background:var(--ink-50); }
    .cpd .page-btn.active{ background:var(--green-600); border-color:var(--green-600); color:var(--white); }
    .cpd .page-btn:disabled{ opacity:.45; cursor:not-allowed; }
    .cpd .pagination.sd-pagination{ display:flex; justify-content:flex-end; }
    .cpd .sd-pagination-content{ display:flex; flex-direction:row; align-items:center; gap:4px; margin:0; padding:0; list-style:none; }
    .cpd .sd-pagination-link{ display:inline-flex; align-items:center; justify-content:center; gap:4px; height:36px; padding:0 16px; border:none; border-radius:6px; background:transparent; color:#18181b; font-family:inherit; font-size:14px; font-weight:500; line-height:20px; white-space:nowrap; cursor:pointer; transition:background-color 0.15s ease, color 0.15s ease; }
    .cpd .sd-pagination-link:hover{ background:#f4f4f5; color:#18181b; }
    .cpd .sd-pagination-link:disabled{ opacity:.45; pointer-events:none; }
    .cpd .sd-pagination-link:focus-visible{ outline:2px solid #18181b; outline-offset:2px; }
    .cpd .sd-pagination-previous{ padding-left:10px; }
    .cpd .sd-pagination-next{ padding-right:10px; }

    /* Right profile */
    .cpd .profile-panel{ padding:18px; border-left:none; display:flex; flex-direction:column; overflow-y:auto; }
    .cpd .profile-panel #profileContent{ flex:1; min-height:0; display:flex; flex-direction:column; }
    .cpd .profile-header{ display:flex; align-items:flex-start; gap:12px; margin-bottom:16px; }
    .cpd .profile-avatar{ width:56px;height:56px; border-radius:50%; background:var(--green-100); color:var(--green-700); font-weight:700; font-size:18px; display:flex;align-items:center;justify-content:center; flex-shrink:0; overflow:hidden; }
    .cpd .profile-avatar img{ width:100%; height:100%; object-fit:cover; }
    .cpd .profile-name-row{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .cpd .profile-name-row h2{ font-size:20px; font-weight:700; margin:0; color:var(--ink-900); font-family:'Inter',sans-serif; }
    .cpd .profile-id{ font-size:13px; font-weight:400; color:var(--ink-500); margin-top:3px; }
    .cpd .close-x{ margin-left:auto; color:var(--ink-400); cursor:pointer; background:none;border:none; padding:4px; }
    .cpd .close-x:hover{ color:var(--ink-700); }
    .cpd .tabs{ display:flex; justify-content:center; gap:28px; border-bottom:1px solid var(--border); margin-bottom:20px; }
    .cpd .tab{ padding:0 0 12px; font-size:14px; font-weight:600; color:var(--ink-400); cursor:pointer; border-bottom:2px solid transparent; background:none;border:none; font-family:inherit; }
    .cpd .tab.active{ color:var(--green-700); border-bottom-color:var(--green-600); }
    .cpd .info-card-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .cpd .info-card-head h3{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; margin:0; color:#71717a; }
    .cpd .info-rows{ display:flex; flex-direction:column; gap:14px; margin-bottom:22px; }
    .cpd .info-row{ display:flex; align-items:flex-start; gap:11px; }
    .cpd .info-row-icon{ width:36px;height:36px; border-radius:9px; background:var(--ink-100); color:#000; display:flex;align-items:center;justify-content:center; flex-shrink:0; }
    .cpd .info-row-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:400; color:#71717a; margin-bottom:2px; }
    .cpd .info-row-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:15px; font-weight:600; color:#18181b; word-break:break-word; }
    .cpd .profile-empty{ text-align:center; padding:48px 12px; color:var(--ink-400); }
    .cpd .profile-empty p{ margin:0; font-size:13px; }
    .cpd .pet-empty{ border:1.5px dashed var(--ink-200); border-radius:var(--radius); padding:44px 16px; }
    .cpd .pet-empty h3{ margin:0 0 6px; font-size:14px; color:var(--ink-600); font-weight:700; }
    .cpd .pet-empty p{ margin:0; font-size:12.5px; line-height:1.5; }
    .cpd .profile-footer{ display:flex; align-items:center; justify-content:flex-end; gap:10px; margin-top:auto; padding-top:16px; border-top:1px solid var(--border); }

    .cpd .rpt-tabs{ display:flex; justify-content:center; gap:28px; border-bottom:1px solid var(--border); margin-bottom:20px; }
    .cpd .rpt-tab{ padding:0 0 12px; font-size:14px; font-weight:600; color:var(--ink-400); cursor:pointer; border-bottom:2px solid transparent; background:none;border:none; font-family:inherit; }
    .cpd .rpt-tab.active{ color:var(--green-700); border-bottom-color:var(--green-600); }
    .cpd .rpt-section-title{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; text-transform:none; letter-spacing:0; color:#71717a; margin:18px 0 10px; }
    .cpd .rpt-desc-box{ background:var(--ink-50); border:1px solid var(--border); border-radius:var(--radius-sm); padding:12px; font-size:13px; color:var(--ink-700); line-height:1.55; white-space:pre-wrap; }
    .cpd .listing-tab .rpt-desc-box{ background:transparent; border:none; border-radius:0; padding:0; }
    .cpd .listing-tab .info-row-icon{ background:transparent; }
    .cpd .rpt-history{ list-style:none; margin:0; padding:0; }
    .cpd .rpt-history li{ position:relative; padding:0 0 14px 20px; font-size:12.5px; color:var(--ink-600); }
    .cpd .rpt-history li:before{ content:''; position:absolute; left:4px; top:5px; width:8px;height:8px; border-radius:50%; background:var(--green-500); }
    .cpd .rpt-history li:last-child{ padding-bottom:0; }
    .cpd .rpt-history li .h-date{ display:block; font-size:11px; color:var(--ink-400); margin-top:2px; }

    /* Modal form helpers */
    .cpd-form-grid{ display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    .cpd-form-grid .form-group-full{ grid-column:1 / -1; }
    .cpd .form-label{ display:block; font-size:12.5px; font-weight:600; color:var(--ink-700); margin-bottom:6px; }

    .cpd-toast{ position:fixed; bottom:24px; right:24px; z-index:10000; padding:13px 18px; border-radius:12px; color:#fff; font-size:13.5px; font-weight:600; box-shadow:var(--shadow-md); display:flex; align-items:center; gap:10px; transform:translateY(20px); opacity:0; pointer-events:none; transition:all .25s ease; }
    .cpd-toast.show{ transform:translateY(0); opacity:1; }
    .cpd-toast.success{ background:var(--green-700); }
    .cpd-toast.error{ background:#dc2626; }

    .cpd .no-results{ text-align:center; padding:48px 12px; color:var(--ink-400); }
    .cpd .no-results i{ font-size:34px; margin-bottom:10px; color:var(--ink-300); display:block; }
    .cpd .no-results p{ margin:0; font-size:13px; }

    /* ── Pet-specific ── */
    .cpd .pet-thumb{ width:42px;height:42px; border-radius:9px; overflow:hidden; background:var(--ink-100); display:flex;align-items:center;justify-content:center; color:var(--ink-400); flex-shrink:0; }
    .cpd .pet-thumb img{ width:100%;height:100%;object-fit:cover; display:block; }
    .cpd .pet-cell{ display:flex; align-items:center; gap:10px; min-width:0; }

    .cpd .rpt-cards .card-img{ width:100%; height:150px; object-fit:cover; border-radius:var(--radius-sm) var(--radius-sm) 0 0; display:block; background:var(--ink-100); }
    .cpd .rpt-cards .card-img-placeholder{ width:100%; height:150px; border-radius:var(--radius-sm) var(--radius-sm) 0 0; background:var(--ink-100); display:flex;align-items:center;justify-content:center; color:var(--ink-300); }

    .cpd .pet-img{ position:relative; width:100%; height:250px; background:var(--ink-100); }
    .cpd .pet-img img{ width:100%; height:100%; object-fit:cover; display:block; }
    .cpd .pet-img-placeholder{ width:100%; height:100%; display:flex;align-items:center;justify-content:center; color:var(--ink-300); font-size:42px; }
    .cpd .pet-card-wrap{ display:flex; flex-direction:column; gap:8px; cursor:pointer; width:250px; margin:0 auto; }
    .cpd .pet-card-wrap .rpt-card{ border-radius:0; border:1px solid var(--border); cursor:pointer; width:100%; }
    .cpd .pet-card-wrap .pet-img{ height:250px; }
    .cpd .pet-card-caption{ display:flex; flex-direction:row; align-items:baseline; justify-content:space-between; gap:10px; padding:0 2px; width:100%; }
    .cpd .pet-card-caption .pet-card-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; color:#18181b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .cpd .pet-card-caption .rpt-card-ref{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; text-align:right; flex-shrink:0; }
    .cpd .pet-status-badge{ position:absolute; top:8px; right:8px; display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:0; font-size:11.5px; font-weight:700; box-shadow:0 1px 4px rgba(15,23,32,.12); }
    .cpd .pet-card-body{ display:flex; flex-direction:column; gap:6px; flex:1; padding:14px 16px 12px; }
    .cpd .pet-card-name{ font-size:20px; font-weight:800; color:var(--ink-900); line-height:1.25; }
    .cpd .rpt-card-ref{ font-size:13px; font-weight:400; color:var(--ink-500); }
    .cpd .vacc-badge{ display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:0; font-size:13px; font-weight:700; white-space:nowrap; background:transparent; color:#000; }
    .cpd .vacc-ok{ background:transparent; color:#000; }
    .cpd .vacc-no{ background:transparent; color:#000; }
    .cpd .vacc-unknown{ background:transparent; color:#000; }
    .cpd .pet-card-date{ font-size:14px; color:var(--ink-600); margin-top:3px; }
    .cpd .pet-card-foot{ display:flex; align-items:center; justify-content:center; gap:10px; margin-top:12px; padding-top:12px; border-top:1px solid var(--border); flex-wrap:wrap; }
    .cpd .pet-card-view{ display:inline-flex; align-items:center; justify-content:center; gap:7px; flex:1; min-width:110px; padding:9px 12px; border:1px solid var(--border); background:var(--white); color:var(--ink-700); font-family:inherit; font-size:13px; font-weight:700; cursor:pointer; }
    .cpd .pet-card-view:hover{ background:var(--ink-50); }
    .cpd .pet-card-unpublish{ display:inline-flex; align-items:center; justify-content:center; gap:7px; flex:1; min-width:110px; padding:9px 12px; border:1px solid #fecaca; background:var(--white); color:#b91c1c; font-family:inherit; font-size:13px; font-weight:700; cursor:pointer; }
    .cpd .pet-card-unpublish:hover{ background:#fef2f2; }
    .cpd .pet-card-relist{ display:inline-flex; align-items:center; justify-content:center; gap:7px; flex:1; min-width:110px; padding:9px 12px; border:1px solid #bbf7d0; background:var(--white); color:#15803d; font-family:inherit; font-size:13px; font-weight:700; cursor:pointer; }
    .cpd .pet-card-relist:hover{ background:#f0fdf4; }

    .cpd .species-badge{ display:inline-block; padding:3px 9px; border-radius:0; font-size:11px; font-weight:700; }
    .cpd .species-badge.species-dog{ background:#dbeafe; color:#1d4ed8; }
    .cpd .species-badge.species-cat{ background:#fef3c7; color:#b45309; }

    /* Status pills: listing + pet */
    .cpd .ls-available{ background:var(--green-100); color:var(--green-700); }
    .cpd .ls-available .st-dot{ background:var(--green-500); }
    .cpd .ls-adopted{ background:#eff6ff; color:#1d4ed8; }
    .cpd .ls-adopted .st-dot{ background:#2563eb; }
    .cpd .ls-withdrawn{ background:var(--ink-100); color:var(--ink-500); }
    .cpd .ls-withdrawn .st-dot{ background:var(--ink-400); }
    .cpd .ps-active{ background:var(--green-100); color:var(--green-700); }
    .cpd .ps-active .st-dot{ background:var(--green-500); }
    .cpd .ps-adopted{ background:#eff6ff; color:#1d4ed8; }
    .cpd .ps-adopted .st-dot{ background:#2563eb; }

    .cpd .pet-detail-head{ display:flex; align-items:center; gap:16px; margin-bottom:18px; }
    .cpd .pet-detail-img{ width:96px;height:96px; border-radius:14px; overflow:hidden; background:var(--ink-100); display:flex;align-items:center;justify-content:center; color:var(--ink-300); font-size:38px; flex-shrink:0; }
    .cpd .pet-detail-img img{ width:100%;height:100%;object-fit:cover; display:block; }
    .cpd .pet-detail-badges{ display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-top:8px; }
    .cpd .pet-detail-meta{ font-size:14px; color:var(--ink-500); margin-top:6px; }
    .cpd .info-grid{ display:grid; grid-template-columns:1fr 1fr; gap:10px 14px; margin-bottom:20px; }
    .cpd .info-cell-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:400; color:#71717a; margin-bottom:2px; }
    .cpd .info-cell-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:15px; font-weight:600; color:#18181b; }
    .cpd .readiness-table{ width:100%; border-collapse:collapse; margin-bottom:22px; }
    .cpd .readiness-table th{ text-align:left; font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:400; color:#71717a; padding:6px 8px; border-bottom:1px solid var(--border); background:transparent; text-transform:none; }
    .cpd .readiness-table td{ padding:9px 8px; font-family:'Inter','Segoe UI',sans-serif; font-size:15px; font-weight:600; color:#18181b; border-bottom:1px solid var(--ink-100); }
    .cpd .custodian-card{ display:flex; align-items:center; gap:12px; padding:14px 0; margin-bottom:6px; }
    .cpd .custodian-avatar{ width:56px;height:56px; border-radius:50%; background:var(--green-100); color:var(--green-700); font-weight:700; font-size:18px; display:flex;align-items:center;justify-content:center; flex-shrink:0; overflow:hidden; }
    .cpd .custodian-avatar img{ width:100%; height:100%; object-fit:cover; display:block; }
    .cpd .custodian-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; margin-bottom:2px; }
    .cpd .custodian-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:400; color:#71717a; }

    .cpd-form-grid input[type="text"], .cpd-form-grid input[type="url"], .cpd-form-grid input[type="datetime-local"],
    .cpd-form-grid select, .cpd-form-grid textarea{ width:100%; padding:0.62rem 0.8rem; border:1.5px solid var(--ink-200); border-radius:9px; font-size:0.88rem; font-family:inherit; color:var(--ink-900); transition:border .15s, box-shadow .15s; box-sizing:border-box; background:#fff; }
    .cpd-form-grid input:focus, .cpd-form-grid select:focus, .cpd-form-grid textarea:focus{ outline:none; border-color:var(--green-600); box-shadow:0 0 0 3px rgba(4,107,37,.12); }
    .cpd-form-grid textarea{ resize:vertical; min-height:110px; line-height:1.5; }
    .cpd-form-grid select{ cursor:pointer; }

    .cpd .photo-upload-area{ position:relative; border:2px dashed var(--ink-200); border-radius:10px; padding:1.4rem; text-align:center; cursor:pointer; transition:all .2s; background:var(--ink-50); }
    .cpd .photo-upload-area:hover{ border-color:var(--green-600); background:var(--green-50); }
    .cpd .photo-upload-area.has-image{ border-style:solid; padding:0.5rem; }
    .cpd .photo-upload-area img{ max-height:140px; border-radius:6px; display:block; margin:0 auto; }
    .cpd .photo-upload-area .upload-placeholder i{ font-size:1.7rem; color:var(--ink-400); display:block; margin-bottom:0.35rem; }
    .cpd .photo-upload-area .upload-placeholder span{ font-size:0.8rem; color:var(--ink-400); }
    .cpd .photo-upload-area input[type="file"]{ position:absolute; inset:0; opacity:0; cursor:pointer; }

    .pets-alerts{ padding:1.25rem 2rem 0; }

    @media (max-width:1280px) and (min-width:1151px){
      .cpd .layout{ grid-template-columns:minmax(0,1fr) 400px; }
    }
    @media (max-width:1400px) and (min-width:1151px){
      .cpd .cards-grid{ grid-template-columns:repeat(2, minmax(0,1fr)); }
    }
    @media (max-width:1150px){
      .cpd .layout{ grid-template-columns:minmax(0,1fr); }
      .cpd .db-panel{ border-right:none; }
      .cpd .profile-panel{ position:fixed; top:var(--topbar-h); right:0; bottom:0; left:auto; width:min(440px,94vw); z-index:150; background:var(--white); border-left:1px solid var(--border); box-shadow:-8px 0 40px rgba(15,23,32,.18); transform:translateX(105%); transition:transform .3s cubic-bezier(.32,.72,.25,1); }
      .cpd .profile-panel.open{ transform:translateX(0); }
    }
    @media (max-width:1180px){
      .cpd .kpis{ grid-template-columns:repeat(2,1fr); }
    }
    @media (max-width:820px){
      .cpd .layout{ grid-template-columns:1fr; }
      .cpd .db-panel{ border-right:none; border-bottom:1px solid var(--border); border-radius:0; }
      .cpd .profile-panel{ top:0; width:94vw; }
      .cpd-form-grid{ grid-template-columns:1fr; }
      .cpd .kpis{ grid-template-columns:1fr 1fr; }
      .pets-alerts{ padding:1rem 0.75rem 0; }
      .cpd .db-toolbar{ flex-wrap:wrap; }
    }
    @media (max-width:760px){
      .cpd .cards-grid{ grid-template-columns:repeat(2, minmax(0,1fr)); }
    }
    @media (max-width:520px){
      .cpd .cards-grid{ grid-template-columns:1fr; }
    }

    /* ── Dialog font + button style (shadcn) — Pet Listings ── */
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

    .pets-cms .cpd{ font-family:'Inter','Segoe UI',sans-serif; }
    /* KPI cards */
    .pets-cms .kpi-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin:0 0 6px; }
    .pets-cms .kpi-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; color:#18181b; letter-spacing:-0.02em; line-height:1; margin:0 0 6px; }
    .pets-cms .kpi-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }
    /* search bar */
    .pets-cms .search-grow-wrap input{ font-family:'Inter','Segoe UI',sans-serif; }
    .pets-cms .search-grow-wrap input::placeholder{ color:#a1a1aa; }
    /* toolbar filter selects → dialog outline button look */
    .pets-cms .db-toolbar select.select-input{ height:36px; padding:0 26px 0 10px; border-radius:6px; border:1px solid #e4e4e7; background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 10px center; font-size:14px; font-weight:500; color:#18181b; font-family:inherit; cursor:pointer; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); }
    .pets-cms .db-toolbar select.select-input:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    /* pet card text */
    .pets-cms .pet-card-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:16px; font-weight:600; color:#18181b; }
    .pets-cms .rpt-card-ref{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; }
    .pets-cms .pet-card-date{ font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:400; color:#71717a; }
    /* card action buttons → dialog outline button look */
    .pets-cms .pet-card-view,
    .pets-cms .pet-card-unpublish,
    .pets-cms .pet-card-relist{ height:36px; border-radius:6px; box-shadow:0 1px 2px 0 rgba(0,0,0,0.05); font-size:13px; font-weight:500; }
    .pets-cms .pet-card-view{ border:1px solid #e4e4e7; background:#fff; color:#18181b; }
    .pets-cms .pet-card-view:hover{ background:#f4f4f5; }
    .pets-cms .pet-card-unpublish{ border:1px solid #fecaca; background:#fff; color:#b91c1c; }
    .pets-cms .pet-card-unpublish:hover{ background:#fef2f2; }
    .pets-cms .pet-card-relist{ border:1px solid #bbf7d0; background:#fff; color:#15803d; }
    .pets-cms .pet-card-relist:hover{ background:#f0fdf4; }
    /* right panel info */
    .pets-cms .profile-name-row h2{ font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; color:#18181b; letter-spacing:-0.025em; }
    .pets-cms .profile-id{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; }
    .pets-cms .tab{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#71717a; }
    .pets-cms .tab.active{ color:#18181b; }
    .pets-cms .info-card-head h3{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    .pets-cms .info-row-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; margin-bottom:2px; }
    .pets-cms .info-row-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    .pets-cms .info-cell-label{ font-family:'Inter','Segoe UI',sans-serif; font-size:11px; font-weight:500; color:#71717a; margin-bottom:2px; }
    .pets-cms .info-cell-value{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    .pets-cms .pet-detail-meta{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }
    .pets-cms .profile-empty p{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }
    /* pagination (all-clients design) */
    .pets-cms .dt-pagination{ display:flex; align-items:center; justify-content:flex-end; gap:12px; flex-wrap:wrap; padding:12px 8px; }
    .pets-cms .dt-pagination-right{ display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
    .pets-cms .dt-rows{ display:flex; align-items:center; gap:8px; font-size:14px; color:#71717a; }
    .pets-cms .dt-rows select{ height:32px; width:70px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; padding:0 8px; font-size:14px; color:#18181b; cursor:pointer; font-family:inherit; }
    .pets-cms .dt-rows select:focus{ outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    .pets-cms .dt-page-label{ width:110px; text-align:center; font-size:14px; font-weight:500; color:#18181b; white-space:nowrap; }
    .pets-cms .dt-page-btns{ display:flex; align-items:center; gap:4px; }
    .pets-cms .dt-page-btn{ height:32px; width:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .pets-cms .dt-page-btn:hover:not(:disabled){ background:#f4f4f5; }
    .pets-cms .dt-page-btn:disabled{ opacity:.5; cursor:not-allowed; }
    .pets-cms .sd-row-actions{ width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; background:transparent; border:none; color:#71717a; cursor:pointer; }
    .pets-cms .sd-row-actions:hover{ background:#f4f4f5; color:#18181b; }
    /* ── shadcn data-table (exact client-table look) ── */
    .pets-cms .dt-container{ overflow:hidden; border:1px solid #e4e4e7; border-radius:8px; background:#fff; }
    .pets-cms table{ width:100%; border-collapse:collapse; color:#18181b; font-size:14px; }
    .pets-cms thead tr{ border-bottom:1px solid #e4e4e7; }
    .pets-cms thead th{ height:40px; padding:0 8px; text-align:left; vertical-align:middle; font-size:14px; font-weight:500; color:#71717a; white-space:nowrap; background:#fafafa; border-bottom:none; }
    .pets-cms thead th.sd-th-action{ text-align:right; }
    .pets-cms tbody tr{ border-bottom:1px solid #e4e4e7; transition:background .15s; cursor:pointer; }
    .pets-cms tbody tr:last-child{ border-bottom:none; }
    .pets-cms tbody tr:hover{ background:#fafafa; }
    .pets-cms tbody tr.selected, .pets-cms tbody tr.selected:hover{ background:#f4f4f5; }
    .pets-cms tbody td{ padding:8px; vertical-align:middle; font-size:14px; color:#18181b; }
    .pets-cms tbody td.sd-td-action{ text-align:right; }
    .pets-cms .th-sort{ display:inline-flex; align-items:center; gap:6px; height:28px; margin-left:-12px; padding:0 12px; border-radius:6px; background:transparent; border:none; font-size:14px; font-weight:500; color:#71717a; cursor:pointer; white-space:nowrap; font-family:inherit; }
    .pets-cms .th-sort:hover{ background:#f4f4f5; color:#18181b; }
    .pets-cms .th-sort.th-sorted{ color:#18181b; }
    .pets-cms .th-sort-icon{ width:16px; height:16px; color:#71717a; flex-shrink:0; }
    .pets-cms .th-sorted .th-sort-icon{ color:#18181b; }
    .pets-cms .client-cell{ display:flex; align-items:center; gap:10px; }
    .pets-cms .avatar{ width:44px; height:44px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px; color:var(--green-700); background:var(--green-100); flex-shrink:0; overflow:hidden; }
    .pets-cms .avatar img{ width:100%; height:100%; object-fit:cover; }
    .pets-cms .client-name{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:600; color:#18181b; }
    .pets-cms .client-id{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; margin-top:0; }
    .pets-cms .contact-main{ font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; color:#18181b; }
    .pets-cms .contact-sub{ font-family:'Inter','Segoe UI',sans-serif; font-size:12px; font-weight:400; color:#71717a; margin-top:2px; }
    .pets-cms .vax-pill{ display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:4px; font-family:'Inter',sans-serif; font-size:12px; font-weight:500; white-space:nowrap; line-height:1.4; border:1px solid #e4e4e7; }
    .pets-cms .vacc-ok{ background:#ECFDF3; color:#027A48; border-color:#D1FADF; }
    .pets-cms .vacc-no{ background:#FEE2E2; color:#991B1B; border-color:#FECACA; }
    .pets-cms .vacc-unknown{ background:#F4F4F5; color:#71717A; border-color:#E4E4E7; }
    .pets-cms .status-pill{ display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:4px; font-family:'Inter',sans-serif; font-size:12px; font-weight:500; white-space:nowrap; line-height:1.4; border:1px solid #e4e4e7; }
    .pets-cms .ls-available{ background:#f0fdf4; color:#166534; border-color:#bbf7d0; }
    .pets-cms .ls-adopted{ background:#eff6ff; color:#1e40af; border-color:#bfdbfe; }
    .pets-cms .ls-withdrawn{ background:#f4f4f5; color:#52525b; border-color:#e4e4e7; }
    /* Pet listings: full-width table + right drawer detail panel */
    .pets-reports .layout{ grid-template-columns:minmax(0,1fr); min-height:calc(100vh - var(--topbar-h)); }
    .pets-reports .db-panel{ border-right:none; }
    #petProfileModal.modal{ position:fixed; inset:0; background:rgba(0,0,0,0.5); padding:0; z-index:999; display:flex; align-items:stretch; justify-content:flex-end; opacity:0; visibility:hidden; transition:opacity .25s ease, visibility .25s ease; }
    #petProfileModal.modal.active{ opacity:1; visibility:visible; }
    #petProfileModal .sd-dialog{ height:100%; width:100%; display:flex; justify-content:flex-end; }
    #petProfileModal .sd-dialog-content{ position:relative; width:100%; max-width:min(480px,92vw); width:min(480px,92vw); height:100%; max-height:none; overflow-y:auto; background:#ffffff; border:none; border-radius:0; padding:24px; display:flex; flex-direction:column; gap:18px; box-shadow:-8px 0 40px rgba(15,23,32,.18); animation:none; transform:translateX(105%); transition:transform .32s cubic-bezier(.32,.72,.25,1); }
    #petProfileModal.active .sd-dialog-content{ transform:translateX(0); }
    #petProfileModal .sd-dialog-close{ position:absolute; top:16px; right:16px; width:24px; height:24px; display:inline-flex; align-items:center; justify-content:center; border-radius:4px; border:none; background:transparent; color:#71717a; cursor:pointer; transition:all .15s ease; z-index:2; }
    #petProfileModal .sd-dialog-close:hover{ background:#f4f4f5; color:#18181b; }
    #petProfileModal .sd-dialog-header{ display:flex; flex-direction:column; gap:6px; text-align:left; }
    #petProfileModal .sd-dialog-title{ margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
    #petProfileModal .sd-dialog-description{ margin:0; font-size:14px; color:#71717a; line-height:1.5; }
    #petProfileModal .sd-field-group{ flex:1; min-height:0; overflow-y:auto; display:flex; flex-direction:column; gap:16px; }
    #petProfileModal .sd-dialog-footer{ flex-shrink:0; display:flex; flex-direction:row; justify-content:center; gap:8px; }
  </style>
</head>
<body class="staff-theme" data-portal="staff">

<?php include __DIR__ . '/../../includes/staff-sidebar.php'; ?>

<main class="main-content pets-cms">

  <?php if ($error || $success): ?>
    <div class="pets-alerts">
      <?php if ($error): ?>
        <div class="auth-alert auth-alert-error" style="margin:0 0 1rem;"><i class="fas fa-exclamation-circle"></i> <span><?php echo htmlspecialchars($error); ?></span></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="auth-alert auth-alert-success" style="margin:0 0 1rem;"><i class="fas fa-check-circle"></i> <span><?php echo htmlspecialchars($success); ?></span></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="cpd pets-reports reports-fullpage">

    <div class="cpd-app">
      <div class="layout">

        <!-- Center: Database -->
        <div class="panel db-panel">
          <div class="kpis">
            <div class="kpi-card">
              <p class="kpi-label">Total Listings</p>
              <div class="kpi-value" id="kpiTotal">0</div>
              <p class="kpi-sub">Pets on the adoption page</p>
            </div>
            <div class="kpi-card">
              <p class="kpi-label">Available</p>
              <div class="kpi-value" id="kpiAvailable">0</div>
              <p class="kpi-sub">Ready for adoption</p>
            </div>
            <div class="kpi-card">
              <p class="kpi-label">Adopted</p>
              <div class="kpi-value" id="kpiAdopted">0</div>
              <p class="kpi-sub">Re-homed to families</p>
            </div>
            <div class="kpi-card">
              <p class="kpi-label">Withdrawn</p>
              <div class="kpi-value" id="kpiWithdrawn">0</div>
              <p class="kpi-sub">Hidden from public</p>
            </div>
          </div>

          <div class="db-table-area">
            <div class="db-toolbar">
              <div class="search-grow-wrap">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="mainSearch" placeholder="Search pet name, ID, species, owner..." oninput="setSearch(this.value)">
              </div>
              <div class="toolbar-actions">
                <select class="select-input" id="speciesSelect" onchange="setSpecies(this.value)">
                  <option value="all">All Species</option>
                  <option value="Dog">Dog</option>
                  <option value="Cat">Cat</option>
                </select>
                <select class="select-input" id="sexSelect" onchange="setSex(this.value)">
                  <option value="all">All Sex</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                </select>
                <select class="select-input" id="vaccSelect" onchange="setVacc(this.value)">
                  <option value="all">Adoption Readiness</option>
                  <option value="vaccinated">Vaccinated</option>
                  <option value="not_vaccinated">Not Vaccinated</option>
                  <option value="needs_review">Medical Review Needed</option>
                </select>
                <button type="button" class="sd-btn sd-btn-default" onclick="openCreate()"><i class="fas fa-plus"></i> Add New Pet</button>
              </div>
            </div>
            <div id="tableWrap" class="dt-container">
              <div style="overflow-x:auto;">
                <table>
                  <thead id="tableHead"></thead>
                  <tbody id="dataRows"></tbody>
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

      <!-- Pet Details Drawer (right drawer) -->
      <div id="petProfileModal" class="modal cpd">
        <div class="sd-dialog">
          <div class="sd-dialog-content" role="dialog" aria-modal="true">
            <button type="button" class="sd-dialog-close" onclick="closeModal('petProfileModal')" aria-label="Close">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <div class="sd-dialog-header" style="padding-bottom:4px;">
              <h2 class="sd-dialog-title">Pet Details</h2>
              <p class="sd-dialog-description">Review pet profile, listing, and adoption history.</p>
            </div>
            <div id="petProfileTabs" class="rpt-tabs" style="margin-bottom:4px;"></div>
            <div id="petProfileHeader"></div>
            <div id="profileContent"></div>
            <div class="sd-dialog-footer" id="petProfileFooter"></div>
          </div>
        </div>
      </div>

    <!-- Row menu popup -->
    <div class="cpd row-menu-pop" id="rowMenuPop"></div>

    <!-- Create/Edit Pet Modal (shadcn Dialog) -->
    <style>
      #petModal.modal{ background:rgba(0,0,0,0.8); padding:1rem; z-index:999; }
      #petModal .sd-dialog{ width:100%; display:flex; justify-content:center; }
      #petModal .sd-dialog-content{
        position:relative; width:100%; max-width:600px;
        background:#ffffff; border:1px solid #e4e4e7; border-radius:8px;
        padding:24px; display:flex; flex-direction:column; gap:16px;
        box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1);
        animation:modalIn 0.2s ease;
      }
      #petModal .sd-dialog-close{
        position:absolute; top:16px; right:16px; width:24px; height:24px;
        display:inline-flex; align-items:center; justify-content:center;
        border-radius:4px; border:none; background:transparent;
        color:#71717a; cursor:pointer; transition:all 0.15s ease;
      }
      #petModal .sd-dialog-close:hover{ background:#f4f4f5; color:#18181b; }
      #petModal .sd-dialog-header{ display:flex; flex-direction:column; gap:6px; text-align:left; }
      #petModal .sd-dialog-title{ margin:0; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; line-height:1; letter-spacing:-0.025em; color:#18181b; }
      #petModal .sd-dialog-description{ margin:0; font-size:14px; color:#71717a; line-height:1.5; }
      #petModal .sd-field-group{ display:flex; flex-direction:column; gap:12px; }
      #petModal .sd-form-grid{ display:grid; grid-template-columns:1fr 1fr; gap:16px; }
      #petModal .sd-field-full{ grid-column:1 / -1; }
      #petModal .sd-field{ display:flex; flex-direction:column; gap:6px; }
      #petModal .sd-label{ font-size:14px; font-weight:500; line-height:1; color:#18181b; }
      #petModal .sd-label .req{ color:#dc2626; }
      #petModal .sd-input{
        width:100%; height:36px; padding:0 12px;
        font-family:inherit; font-size:14px; color:#18181b;
        background:transparent; border:1px solid #e4e4e7; border-radius:6px;
        box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);
        outline:none; transition:box-shadow 0.15s ease, border-color 0.15s ease;
      }
      #petModal .sd-input::placeholder{ color:#a1a1aa; }
      #petModal .sd-input:focus-visible{ border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
      #petModal select.sd-input{
        appearance:none; -webkit-appearance:none;
        background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
        background-repeat:no-repeat; background-position:right 12px center;
        padding-right:36px;
      }
      #petModal textarea.sd-input{ height:auto; min-height:90px; padding:8px 12px; resize:vertical; line-height:1.5; }
      #petModal .sd-photo-upload{
        position:relative; display:flex; align-items:center; justify-content:center;
        min-height:90px; border:2px dashed #e4e4e7; border-radius:6px;
        background:#fafafa; cursor:pointer; transition:border-color 0.15s ease, background 0.15s ease;
      }
      #petModal .sd-photo-upload.has-image{ border:none; background:transparent; padding:0; min-height:0; }
      #petModal .sd-photo-upload img{ max-height:220px; max-width:100%; border-radius:8px; display:block; margin:0 auto; }
      #petModal .sd-photo-upload .upload-placeholder i{ font-size:1.7rem; color:#a1a1aa; display:block; margin-bottom:0.35rem; }
      #petModal .sd-photo-upload .upload-placeholder span{ font-size:0.8rem; color:#a1a1aa; }
      #petModal .sd-photo-upload input[type="file"]{ position:absolute; inset:0; opacity:0; cursor:pointer; }
      #petModal .sd-dialog-footer{ display:flex; flex-direction:column-reverse; gap:8px; }
      @media (min-width:640px){ #petModal .sd-dialog-footer{ flex-direction:row; justify-content:flex-end; } }
      #petModal .sd-btn{
        display:inline-flex; align-items:center; justify-content:center; gap:8px;
        height:36px; padding:0 16px; border-radius:6px; border:1px solid transparent;
        font-family:inherit; font-size:14px; font-weight:500; line-height:1;
        white-space:nowrap; cursor:pointer;
        box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);
        transition:background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
      }
      #petModal .sd-btn-default{ background:#18181b; color:#fafafa; }
      #petModal .sd-btn-default:hover{ background:#27272a; }
      #petModal .sd-btn-outline{ background:#ffffff; border-color:#e4e4e7; color:#18181b; }
      #petModal .sd-btn-outline:hover{ background:#f4f4f5; }
      #petModal .sd-btn i{ font-size:12px; }
      #petModal .sd-dialog-content{max-height:80vh;overflow-y:auto;}
      #petModal .sd-section{margin-bottom:16px;}
      #petModal .sd-section:last-child{margin-bottom:0;}
      #petModal .sd-section-title{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;color:#18181b;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f4f4f5;}
      #petModal .sd-section-title span{color:#71717a;font-size:12px;font-weight:600;}
      @media (max-width:560px){ #petModal .sd-form-grid{ grid-template-columns:1fr; } }
    </style>
    <div id="petModal" class="modal">
      <form id="petForm" method="POST" enctype="multipart/form-data" action="staff-pets-handler.php" class="sd-dialog">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="formId" value="0">
        <input type="hidden" name="current_photo" id="formCurrentPhoto" value="">
        <div class="sd-dialog-content" role="dialog" aria-modal="true">
          <button type="button" class="sd-dialog-close" onclick="closeModal('petModal')" aria-label="Close">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
          </button>
          <div class="sd-dialog-header">
            <h2 class="sd-dialog-title" id="modalTitle">Add New Pet</h2>
            <p class="sd-dialog-description" id="modalDescription">Fill in the pet's details. Click save when you're done.</p>
          </div>
                              <div class="sd-field-group">
            <div class="sd-section">
              <div class="sd-section-title"><span>01</span> Pet Information</div>
              <div class="sd-form-grid">
                <div class="sd-field sd-field-full">
                  <label class="sd-label" for="formName">Pet Name <span class="req">*</span></label>
                  <input type="text" name="name" id="formName" class="sd-input" required placeholder="e.g. Horsey">
                </div>
                <div class="sd-field">
                  <label class="sd-label" for="formSpecies">Species <span class="req">*</span></label>
                  <select name="species" id="formSpecies" class="sd-input">
                    <option value="Dog">Dog</option>
                    <option value="Cat">Cat</option>
                  </select>
                </div>
                <div class="sd-field sd-field-full">
                  <label class="sd-label" for="formBreed">Breed <span class="req">*</span></label>
                  <input type="text" name="breed" id="formBreed" class="sd-input" required placeholder="e.g. Domestic Shorthair">
                </div>
                <div class="sd-field">
                  <label class="sd-label" for="formGender">Gender <span class="req">*</span></label>
                  <select name="gender" id="formGender" class="sd-input">
                    <option value="Male">Male</option>
                    <option value="Female">Female</option>
                  </select>
                </div>
                <div class="sd-field">
                  <label class="sd-label" for="formAge">Date of Birth / Age <span class="req">*</span></label>
                  <input type="text" name="age" id="formAge" class="sd-input" required placeholder="e.g. 2 years old">
                </div>
                <div class="sd-field">
                  <label class="sd-label" for="formColor">Color <span class="req">*</span></label>
                  <input type="text" name="color" id="formColor" class="sd-input" required placeholder="e.g. Golden">
                </div>
                <div class="sd-field">
                  <label class="sd-label" for="formWeight">Weight <span class="req">*</span></label>
                  <input type="text" name="weight" id="formWeight" class="sd-input" required placeholder="e.g. 5.5 kg">
                <div class="sd-field sd-field-full">
                  <label class="sd-label" for="formDesc">Description <span class="req">*</span></label>
                  <textarea name="description" id="formDesc" class="sd-input" required placeholder="Describe the pet's personality, history, and ideal home..."></textarea>
                </div>
                </div>
              </div>
            </div>
            <div class="sd-section">
              <div class="sd-section-title"><span>02</span> Adoption Information</div>
              <div class="sd-form-grid">
                <div class="sd-field">
                  <label class="sd-label" for="formStatus">Adoption Status</label>
                  <select name="status" id="formStatus" class="sd-input">
                    <option value="available">Available</option>
                    <option value="adopted">Adopted</option>
                    <option value="withdrawn">Withdrawn</option>
                  </select>
                </div>
                <div class="sd-field">
                  <label class="sd-label" for="formRescued">Availability Date <span class="req">*</span></label>
                  <input type="text" name="rescued_date" id="formRescued" class="sd-input" required placeholder="mm/dd/yyyy">
                </div>
                <div class="sd-field">
                  <label class="sd-label" for="formFee">Adoption Fee</label>
                  <input type="text" name="fee" id="formFee" class="sd-input" placeholder="PHP">
                </div>
                <div class="sd-field">
                  <label class="sd-label" for="formLocation">Location</label>
                  <input type="text" name="location" id="formLocation" class="sd-input" value="Dasmariñas City Veterinary Services">
                </div>
                <div class="sd-field sd-field-full">
                  <label class="sd-label" for="formReq">Adoption Requirements</label>
                  <textarea name="requirements" id="formReq" class="sd-input" placeholder="Home visit required..."></textarea>
                </div>
              </div>
            </div>
            <div class="sd-section">
              <div class="sd-section-title"><span>03</span> Medical Information</div>
              <div class="sd-form-grid">
                <div class="sd-field">
                  <label class="sd-label" for="formHealth">Health Status</label>
                  <select name="health" id="formHealth" class="sd-input">
                    <option value="Healthy">Healthy</option>
                    <option value="Under Treatment">Under Treatment</option>
                  </select>
                </div>
                <div class="sd-field">
                  <label class="sd-label" for="formExam">Last Examination</label>
                  <input type="text" name="exam" id="formExam" class="sd-input" placeholder="mm/dd/yyyy">
                </div>
                <div class="sd-field sd-field-full">
                  <label class="sd-label" for="formVax">Vaccinations</label>
                  <textarea name="vaccinations" id="formVax" class="sd-input" placeholder="Rabies, Tetanus..."></textarea>
                </div>
                <div class="sd-field">
                  <label class="sd-label" for="formConditions">Medical Conditions</label>
                  <input type="text" name="conditions" id="formConditions" class="sd-input">
                </div>
                <div class="sd-field">
                  <label class="sd-label" for="formMeds">Medications</label>
                  <input type="text" name="medications" id="formMeds" class="sd-input">
                </div>
              </div>
            </div>
            <div class="sd-section">
              <div class="sd-section-title"><span>04</span> Behavior</div>
              <div class="sd-form-grid">
                <div class="sd-field">
                  <label class="sd-label" for="formTemperament">Temperament</label>
                  <input type="text" name="temperament" id="formTemperament" class="sd-input" placeholder="Gentle, Calm...">
                </div>
                <div class="sd-field">
                  <label class="sd-label" for="formSpecial">Special Requirements</label>
                  <input type="text" name="special" id="formSpecial" class="sd-input">
                </div>
                <div class="sd-field sd-field-full">
                  <label class="sd-label" for="formBehNotes">Behavior Notes</label>
                  <textarea name="behavior_notes" id="formBehNotes" class="sd-input"></textarea>
                </div>
              </div>
            </div>
            <div class="sd-section">
              <div class="sd-section-title"><span>05</span> Photos &amp; Documents</div>
              <div class="sd-form-grid">
                <div class="sd-field sd-field-full">
                  <label class="sd-label">Pet Photos</label>
                  <div class="sd-photo-upload" id="photoArea">
                    <input type="file" name="photo" id="photoInput" accept="image/*" onchange="previewImage(this, 'photoPreview', 'photoArea')">
                    <div class="upload-placeholder" id="photoPlaceholder">
                      <i class="fas fa-paw"></i>
                      <span>Upload Pet Photos</span>
                    </div>
                    <img id="photoPreview" style="display:none;" />
                  </div>
                </div>
                <div class="sd-field sd-field-full">
                  <label class="sd-label">Medical Documents</label>
                  <div class="sd-photo-upload">
                    <input type="file" name="doc" id="docInput" class="sd-photo-upload-input">
                    <div class="upload-placeholder">
                      <i class="fas fa-file-medical"></i>
                      <span>Upload Medical Documents</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
<div class="sd-dialog-footer">
            <button type="button" class="sd-btn sd-btn-outline" onclick="closeModal('petModal')">Cancel</button>
            <button type="submit" name="save_mode" value="draft" class="sd-btn sd-btn-outline">Save Draft</button>
            <button type="submit" class="sd-btn sd-btn-default"><span id="submitBtnText">Publish Pet Listing</span></button>
          </div>
        </div>
      </form>
    </div>

    <!-- Generic Confirm Modal (shadcn Alert Dialog) -->
    <style>
      #confirmModal.modal{ background:rgba(0,0,0,0.6); padding:1rem; }
      #confirmModal .modal-content{
        width:100%; max-width:400px; padding:24px;
        background:#ffffff; border:1px solid #e4e4e7; border-radius:12px;
        box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
        animation:modalIn 0.2s ease;
      }
      #confirmModal .sd-ad-header{ display:flex; flex-direction:column; gap:8px; text-align:left; }
      #confirmModal .sd-ad-title{ margin:0; font-size:18px; font-weight:600; letter-spacing:-0.01em; color:#18181b; }
      #confirmModal .sd-ad-description{ margin:0; font-size:14px; line-height:1.55; color:#71717a; }
      #confirmModal .sd-ad-footer{ display:flex; justify-content:flex-end; gap:8px; margin-top:20px; }
      #confirmModal .sd-ad-btn{
        display:inline-flex; align-items:center; justify-content:center;
        height:38px; padding:0 16px; border-radius:8px;
        font-family:inherit; font-size:14px; font-weight:500; cursor:pointer;
        transition:background-color 0.15s ease, color 0.15s ease, border-color 0.15s ease;
      }
      #confirmModal .sd-ad-cancel{ background:#ffffff; border:1px solid #e4e4e7; color:#18181b; }
      #confirmModal .sd-ad-cancel:hover{ background:#f4f4f5; }
      #confirmModal .sd-ad-action{ background:#dc2626; border:1px solid transparent; color:#ffffff; }
      #confirmModal .sd-ad-action:hover{ background:#b91c1c; }
      #confirmModal .sd-ad-action:disabled{ background:#dc2626; opacity:0.6; cursor:not-allowed; }
    </style>
    <div id="confirmModal" class="modal" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle" aria-describedby="confirmMsg">
      <div class="modal-content">
        <div class="sd-ad-header">
          <h2 class="sd-ad-title" id="confirmTitle">Confirm</h2>
          <p class="sd-ad-description" id="confirmMsg">Are you sure?</p>
        </div>
        <div class="sd-ad-footer">
          <button type="button" class="sd-ad-btn sd-ad-cancel" onclick="closeModal('confirmModal')">Cancel</button>
          <button type="button" class="sd-ad-btn sd-ad-action" id="confirmYesBtn" onclick="confirmYes()">Confirm</button>
        </div>
      </div>
    </div>

    <div class="cpd-toast" id="cpdToast"></div>

  </div>
</main>

<script>
const PL_DATA = <?php echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

const LS_META = {
  available: { label: 'Available', cls: 'ls-available' },
  adopted: { label: 'Adopted', cls: 'ls-adopted' },
  withdrawn: { label: 'Withdrawn', cls: 'ls-withdrawn' }
};
const PS_META = {
  active: { label: 'Active', cls: 'ps-active' },
  adopted: { label: 'Adopted', cls: 'ps-adopted' }
};

let state = {
  listing: 'all',
  quick: 'all',
  species: 'all',
  sex: 'all',
  vacc: [],
  q: '',
  sort: 'newest',
  sortCol: '',
  sortDir: 'asc',
  page: 1,
  per: 9,
  selected: 0,
  tab: 'profile'
};

const DATA = PL_DATA.map(function(p){
  const vaccination = p.vaccination || 'Not specified';
  return {
    id: parseInt(p.listing_id, 10),
    ref: p.ref || '#' + p.listing_id,
    name: p.name || '',
    species: p.species || '',
    breed: p.breed || '',
    age: p.age || '',
    gender: p.gender || '',
    weight: p.weight || '',
    color: p.color || '',
    rescued_date: p.rescued_date || '',
    description: p.description || '',
    photo_url: p.photo_url || '',
    status: p.status || 'available',
    adopted_at: p.adopted_at || '',
    approved_pickup_at: p.approved_pickup_at || '',
    adoption_application_status: p.adoption_application_status || '',
    vaccination: vaccination,
    owner: p.owner || null,
    created_at: p.created_at || '',
    updated_at: p.updated_at || ''
  };
});

/* ───────────────────────── Helpers ───────────────────────── */
function fmtDate(iso){
  if(!iso) return '—';
  const d = new Date(iso);
  if(isNaN(d)) return iso;
  return d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' });
}
function pickupPeriodLabel(p){
  if(p.adoption_application_status === 'expired') return 'Expired / Unclaimed';
  if(p.adoption_application_status !== 'approved' || !p.approved_pickup_at) return '—';
  const scheduledAt = new Date(String(p.approved_pickup_at).replace(' ', 'T'));
  if(Number.isNaN(scheduledAt.getTime())) return '—';
  const remaining = scheduledAt.getTime() + 3 * 24 * 60 * 60 * 1000 - Date.now();
  if(remaining <= 0) return 'Pickup period expired';
  const daysRemaining = Math.min(3, Math.ceil(remaining / (24 * 60 * 60 * 1000)));
  return daysRemaining + (daysRemaining === 1 ? ' day remaining' : (daysRemaining === 3 ? ' days allotted' : ' days remaining'));
}
function fmtDateTime(iso){
  if(!iso) return '—';
  const d = new Date(iso);
  if(isNaN(d)) return iso;
  return d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) + ', ' + d.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit' });
}
function cap(s){ return s ? s.charAt(0).toUpperCase() + s.slice(1) : ''; }
function initials(name){
  if(!name) return '?';
  return name.trim().split(/\s+/).slice(0,2).map(function(w){ return w.charAt(0); }).join('').toUpperCase();
}
function esc(s){
  return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
    return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
  });
}
function listingMeta(s){ return LS_META[s] || { label: cap(s) || s, cls: 'ls-withdrawn' }; }
function petStatus(p){ return p.status === 'adopted' ? 'adopted' : 'active'; }
function ageLabel(a){
  if(!a) return '—';
  if(/\b(year|yr|month|mos)\b/i.test(a)) return a;
  return a + ' years';
}
function vaccMeta(v){
  if(/vaccinated/i.test(v) && !/not/i.test(v)) return { label: v, cls: 'vacc-ok' };
  if(/not vaccinated/i.test(v)) return { label: v, cls: 'vacc-no' };
  return { label: v || 'Not specified', cls: 'vacc-unknown' };
}
function attention(p){
  return /not vaccinated/i.test(p.vaccination) || /not specified/i.test(p.vaccination);
}
function recentWithin(iso, days){
  if(!iso) return false;
  const d = new Date(iso);
  if(isNaN(d)) return false;
  return (Date.now() - d.getTime()) < days * 86400000;
}
function infoRow(icon, label, value, raw){
  const icons = { check:'fa-check-circle', cal:'fa-calendar-alt', tag:'fa-tag', users:'fa-users', user:'fa-user', clock:'fa-clock', pen:'fa-pen', paw:'fa-paw' };
  return '<div class="info-row"><div class="info-row-icon"><i class="fas ' + (icons[icon] || 'fa-info') + '"></i></div><div><p class="info-row-label">' + label + '</p><p class="info-row-value">' + (raw ? value : esc(value)) + '</p></div></div>';
}
function infoCell(label, value){
  return '<div class="info-row" style="display:flex;gap:12px;align-items:flex-start;padding:0;"><div style="display:flex;flex-direction:column;gap:2px;min-width:0;"><p class="info-cell-label" style="font-family:Inter,Segoe UI,sans-serif;font-size:13px;font-weight:400;color:#71717a;margin:0;">' + label + '</p><p class="info-cell-value" style="font-family:Inter,Segoe UI,sans-serif;font-size:15px;font-weight:600;color:#18181b;margin:0;">' + esc(value) + '</p></div></div>';
}

/* ───────────────────────── Filtering / sort ───────────────────────── */
function applyFilters(){
  let items = DATA.slice();
  if(state.listing !== 'all') items = items.filter(p => p.status === state.listing);
  if(state.quick === 'recent_listed') items = items.filter(p => recentWithin(p.created_at, 30));
  if(state.quick === 'recent_updated') items = items.filter(p => recentWithin(p.updated_at || p.created_at, 14));
  if(state.quick === 'needs_attention') items = items.filter(p => attention(p));
  if(state.quick === 'missing_photo') items = items.filter(p => !p.photo_url);
  if(state.species !== 'all') items = items.filter(p => p.species === state.species);
  if(state.sex !== 'all') items = items.filter(p => p.gender.toLowerCase() === state.sex);
  if(state.vacc.length) items = items.filter(p => state.vacc.some(function(v){
    if(v === 'vaccinated') return /vaccinated/i.test(p.vaccination) && !/not/i.test(p.vaccination);
    if(v === 'not_vaccinated') return /not vaccinated/i.test(p.vaccination) || /not specified/i.test(p.vaccination);
    if(v === 'needs_review') return attention(p);
    return false;
  }));
  if(state.q){
    const q = state.q.toLowerCase();
    items = items.filter(p => (p.name + ' ' + p.ref + ' ' + p.species + ' ' + p.breed + ' ' + (p.owner ? p.owner.name : '')).toLowerCase().includes(q));
  }
  if(state.sortCol){
    const dir = state.sortDir === 'desc' ? -1 : 1;
    const col = state.sortCol;
    items.sort(function(a,b){
      let va = a[col], vb = b[col];
      if(col === 'name' || col === 'species' || col === 'gender' || col === 'status' || col === 'owner'){ va = String(va||'').toLowerCase(); vb = String(vb||'').toLowerCase(); }
      if(typeof va === 'number' && typeof vb === 'number') return (va - vb) * dir;
      if(va < vb) return -1 * dir;
      if(va > vb) return 1 * dir;
      return 0;
    });
  } else {
    const s = state.sort;
    items.sort(function(a,b){
      if(s === 'newest') return new Date(b.created_at) - new Date(a.created_at);
      if(s === 'oldest') return new Date(a.created_at) - new Date(b.created_at);
      if(s === 'recent') return new Date(b.updated_at || b.created_at) - new Date(a.updated_at || a.created_at);
      if(s === 'alpha') return a.name.localeCompare(b.name);
      return 0;
    });
  }
  return items;
}

function renderCounts(){
  set('kpiTotal', DATA.length);
  set('kpiAvailable', DATA.filter(p => p.status === 'available').length);
  set('kpiAdopted', DATA.filter(p => p.status === 'adopted').length);
  set('kpiWithdrawn', DATA.filter(p => p.status === 'withdrawn').length);
  set('cnt-list-all', DATA.length);
  set('cnt-list-available', DATA.filter(p => p.status === 'available').length);
  set('cnt-list-adopted', DATA.filter(p => p.status === 'adopted').length);
  set('cnt-list-withdrawn', DATA.filter(p => p.status === 'withdrawn').length);
}
function set(id, n){ const el = document.getElementById(id); if(el) el.textContent = n; }

function render(){
  const items = applyFilters();
  const total = items.length;
  const pages = Math.max(1, Math.ceil(total / state.per));
  if(state.page > pages) state.page = pages;
  if(state.page < 1) state.page = 1;
  const start = (state.page - 1) * state.per;
  const slice = items.slice(start, start + state.per);

  renderTableHead();
  const tbody = document.getElementById('dataRows');
  if(total === 0){
    tbody.innerHTML = '<tr class="sd-tr"><td class="sd-td" colspan="11" style="text-align:center; padding:48px 12px; color:#71717a;"><i class="fas fa-paw" style="font-size:34px; display:block; margin-bottom:10px; color:#d4d4d8;"></i><p style="margin:0; font-size:13px;">No pets found.</p></td></tr>';
  } else {
    tbody.innerHTML = slice.map(rowHtml).join('');
  }

  renderPagination(pages);
}

const COLS = [
  { id: 'name', label: 'Pet' },
  { id: 'ref', label: 'Pet ID' },
  { id: 'species', label: 'Species' },
  { id: 'gender', label: 'Sex' },
  { id: 'age', label: 'Age' },
  { id: 'vaccination', label: 'Vaccination' },
  { id: 'owner', label: 'Owner' },
  { id: 'status', label: 'Status' },
  { id: 'adopted_at', label: 'Confirmed/Adopted Date' },
  { id: 'approved_pickup_at', label: 'Pickup Period' }
];

function sortHeaderHtml(col){
  const active = state.sortCol === col.id;
  let icon;
  if(active && state.sortDir === 'asc') icon = '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>';
  else if(active && state.sortDir === 'desc') icon = '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>';
  else icon = '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>';
  return '<button class="th-sort'+(active?' th-sorted':'')+'" onclick="toggleSort(\''+col.id+'\')">'+col.label+'<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+icon+'</svg></button>';
}
function renderTableHead(){
  const head = document.getElementById('tableHead');
  let html = '<tr class="sd-tr">';
  for(let i=0;i<COLS.length;i++) html += '<th class="sd-th">'+sortHeaderHtml(COLS[i])+'</th>';
  html += '<th class="sd-th sd-th-action"></th>';
  head.innerHTML = html;
}
function toggleSort(col){
  if(state.sortCol === col){ state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc'; }
  else { state.sortCol = col; state.sortDir = 'asc'; }
  state.page = 1;
  render();
}

function petAvatarHtml(p){
  if(p.photo_url) return '<img src="' + esc(p.photo_url) + '" alt="">';
  return '<i class="fas fa-paw" style="font-size:18px;"></i>';
}
function rowHtml(p){
  const selCls = state.selected === p.id ? 'selected' : '';
  const vm = vaccMeta(p.vaccination);
  const lm = listingMeta(p.status);
  const ownerName = (p.owner && p.owner.name) ? esc(p.owner.name) : '—';
  return '<tr class="sd-tr ' + selCls + '" data-id="' + p.id + '" onclick="selectPet(' + p.id + ')">' +
    '<td class="sd-td"><div class="client-cell"><div class="avatar">' + petAvatarHtml(p) + '</div><div class="client-name">' + esc(p.name) + '</div></div></td>' +
    '<td class="sd-td"><span class="client-id">' + esc(p.ref) + '</span></td>' +
    '<td class="sd-td"><span class="contact-main">' + esc(p.species || '—') + '</span></td>' +
    '<td class="sd-td"><span class="contact-main">' + esc(p.gender || '—') + '</span></td>' +
    '<td class="sd-td"><span class="contact-main">' + ageLabel(p.age) + '</span></td>' +
    '<td class="sd-td"><span class="vax-pill ' + vm.cls + '">' + esc(vm.label) + '</span></td>' +
    '<td class="sd-td"><div class="client-name">' + ownerName + '</div></td>' +
    '<td class="sd-td"><span class="status-pill ' + lm.cls + '">' + esc(lm.label) + '</span></td>' +
    '<td class="sd-td"><span class="contact-main">' + (p.adopted_at ? fmtDate(p.adopted_at) : '—') + '</span></td>' +
    '<td class="sd-td"><span class="contact-main">' + esc(pickupPeriodLabel(p)) + '</span></td>' +
    '<td class="sd-td sd-td-action"><button class="sd-row-actions" title="More options" onclick="event.stopPropagation();openRowMenu(event,' + p.id + ')"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>' +
    '</tr>';
}

function goPage(p){
  if(p === 'prev') p = state.page - 1;
  if(p === 'next') p = state.page + 1;
  if(p < 1) return;
  state.page = p;
  render();
}

function renderPagination(pages){
  const prev = document.getElementById('pagePrev');
  const next = document.getElementById('pageNext');
  const first = document.getElementById('pageFirst');
  const last = document.getElementById('pageLast');
  const label = document.getElementById('pageLabel');
  if(prev) prev.disabled = state.page <= 1;
  if(next) next.disabled = state.page >= pages;
  if(first) first.disabled = state.page <= 1;
  if(last) last.disabled = state.page >= pages;
  if(label) label.textContent = 'Page ' + state.page + ' of ' + pages;
  if(first) first.onclick = function(){ state.page = 1; render(); };
  if(prev) prev.onclick = function(){ state.page = Math.max(1, state.page - 1); render(); };
  if(next) next.onclick = function(){ state.page = Math.min(pages, state.page + 1); render(); };
  if(last) last.onclick = function(){ state.page = pages; render(); };
}
function setPerPage(v){
  state.per = parseInt(v, 10);
  state.page = 1;
  render();
}

/* ───────────────────────── State setters ───────────────────────── */
function setListing(v){
  state.listing = v; state.page = 1;
  document.querySelectorAll('[data-rail="listing"]').forEach(el => el.classList.toggle('active', el.dataset.val === v));
  render();
}
function setQuick(v){
  state.quick = state.quick === v ? 'all' : v; state.page = 1;
  document.querySelectorAll('[data-rail="quick"]').forEach(el => el.classList.toggle('active', el.dataset.val === state.quick));
  render();
}
function setSpecies(v){ state.species = v; state.page = 1; render(); }
function setSex(v){ state.sex = v; state.page = 1; render(); }
function setVacc(v){ state.vacc = v === 'all' ? [] : [v]; state.page = 1; render(); }
function setSearch(v){ state.q = v; state.page = 1; render(); }
function setSort(v){ state.sort = v; state.page = 1; render(); }
function clearFilters(){
  state.listing = 'all'; state.quick = 'all'; state.species = 'all'; state.sex = 'all';
  state.vacc = []; state.q = ''; state.page = 1;
  const ms = document.getElementById('mainSearch'); if(ms) ms.value = '';
  const ss = document.getElementById('speciesSelect'); if(ss) ss.value = 'all';
  const sx = document.getElementById('sexSelect'); if(sx) sx.value = 'all';
  const vc = document.getElementById('vaccSelect'); if(vc) vc.value = 'all';
  document.querySelectorAll('[data-rail="listing"],[data-rail="quick"]').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('[data-rail="listing"][data-val="all"]').forEach(el => el.classList.add('active'));
  render();
}
function toggleFilters(){
  var rail = document.getElementById('filterRail');
  if (rail) rail.classList.toggle('hidden');
  document.querySelector('.cpd .layout').classList.toggle('no-filters');
}

/* ───────────────────────── Row menu ───────────────────────── */
function openRowMenu(ev, id){
  ev.stopPropagation();
  const p = DATA.find(x => x.id === id);
  const pop = document.getElementById('rowMenuPop');
  let items = '';
  items += '<button class="row-menu-item" onclick="selectPet(' + id + ')"><i class="fas fa-eye"></i>View Profile</button>';
  items += '<button class="row-menu-item" onclick="openEdit(' + id + ')"><i class="fas fa-pen"></i>Edit Pet</button>';
  if(p.status === 'available') items += '<button class="row-menu-item" onclick="markAdopted(' + id + ')"><i class="fas fa-check-circle"></i>Mark as Adopted</button>';
  if(p.status !== 'withdrawn') items += '<button class="row-menu-item" onclick="unpublishPet(' + id + ')"><i class="fas fa-eye-slash"></i>Unpublish Listing</button>';
  if(p.status === 'withdrawn') items += '<button class="row-menu-item" onclick="relistPet(' + id + ')"><i class="fas fa-undo"></i>Re-list</button>';
  items += '<button class="row-menu-item danger" onclick="confirmDelete(' + id + ')"><i class="fas fa-trash"></i>Delete Permanently</button>';
  pop.innerHTML = items;
  const r = ev.currentTarget.getBoundingClientRect();
  pop.style.top = (r.bottom + 6) + 'px';
  pop.style.left = Math.min(r.left, window.innerWidth - 210) + 'px';
  pop.classList.add('open');
  document.addEventListener('click', closeRowMenuOnce);
}
function closeRowMenuOnce(e){
  if(e.target.closest && e.target.closest('.row-menu-pop')) return;
  document.getElementById('rowMenuPop').classList.remove('open');
  document.removeEventListener('click', closeRowMenuOnce);
}

/* ───────────────────────── Selection & details panel ───────────────────────── */
function selectPet(id){
  state.selected = id;
  state.tab = 'profile';
  document.querySelectorAll('#dataRows tr').forEach(r => r.classList.toggle('selected', r.dataset.id == id));
  openModal('petProfileModal');
  renderDetail();
}
function closeProfile(){
  state.selected = 0;
  closeModal('petProfileModal');
  document.getElementById('profileContent').innerHTML =
    '<div class="profile-empty pet-empty"><h3>Pet Details</h3><p>Select a pet from the list to view its photo, profile, listing, and adoption history.</p></div>';
}
function renderDetail(){
  const p = DATA.find(x => x.id === state.selected);
  const box = document.getElementById('profileContent');
  if(!p){ closeProfile(); return; }
  const lm = listingMeta(p.status);
  const img = p.photo_url
    ? '<img src="' + esc(p.photo_url) + '" alt="">'
    : '<i class="fas fa-paw"></i>';
  box.innerHTML = '<div id="tabBody"></div>';

  document.getElementById('petProfileHeader').innerHTML =
    '<div style="margin-bottom:14px;display:flex;align-items:center;gap:12px;">' +
      '<div class="pet-detail-img" style="width:85px;height:85px;border-radius:8px;flex-shrink:0;overflow:hidden;background:#e4e4e7;display:flex;align-items:center;justify-content:center;">' + img + '</div>' +
      '<div style="display:flex;flex-direction:column;gap:2px;min-width:0;">' +
        '<span style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:12px;font-weight:400;color:#71717a;">For pet adoption</span>' +
        '<span style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:18px;font-weight:600;color:#18181b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(p.name) + '</span>' +
      '</div>' +
      '<span class="status-pill ' + listingMeta(p.status).cls + '" style="margin-left:auto;font-size:12px;font-weight:500;padding:3px 10px;border-radius:4px;font-family:\'Inter\',sans-serif;white-space:nowrap;line-height:1.4;border:1px solid #e4e4e7;">' + esc(listingMeta(p.status).label) + '</span>' +
    '</div>';

  document.getElementById('petProfileTabs').innerHTML =
    '<button class="rpt-tab' + (state.tab==='profile'?' active':'') + '" data-tab="profile" onclick="setTab(\'profile\')">Profile</button>' +
    '<button class="rpt-tab' + (state.tab==='history'?' active':'') + '" data-tab="history" onclick="setTab(\'history\')">History</button>';
  var footer = document.getElementById('petProfileFooter');
  if (footer) {
    var unpublishBtn = p.status === 'withdrawn'
      ? '<button class="sd-btn sd-btn-outline" style="color:#15803d;border-color:#bbf7d0;" onclick="relistPet(' + p.id + ')">Re-list</button>'
      : '<button class="sd-btn sd-btn-outline" style="color:#b91c1c;border-color:#fecaca;" onclick="unpublishPet(' + p.id + ')">Unpublish</button>';
    footer.innerHTML =
      '<button class="sd-btn sd-btn-default" onclick="openEdit(' + p.id + ')">Edit</button>' +
      unpublishBtn;
  }
  renderTab();
}
function setTab(t){
  state.tab = t;
  document.querySelectorAll('#petProfileTabs .rpt-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === t));
  renderTab();
}
function renderTab(){
  const p = DATA.find(x => x.id === state.selected);
  const body = document.getElementById('tabBody');
  if(!p) return;
  if(state.tab === 'profile') body.innerHTML = profileTabHtml(p);
  else body.innerHTML = historyTabHtml(p);
}

function profileTabHtml(p){
  const vm = vaccMeta(p.vaccination);
  const needsReview = attention(p);
  const health = needsReview ? 'Needs medical review' : 'Good health';
  const ready = p.status === 'available';
  const cust = p.owner
    ? '<div class="custodian-card"><div class="custodian-avatar">' + (p.owner.avatar ? '<img src="' + esc(p.owner.avatar) + '" alt="">' : initials(p.owner.name)) + '</div><div style="min-width:0;flex:1;"><div class="custodian-name">' + esc(p.owner.name) + '</div><div class="custodian-sub">' + esc(p.owner.ref) + ' • ' + esc(p.owner.address) + '</div></div><a class="btn btn-sm" href="dashboard-staff.php#client-pets">View Client</a></div>'
    : '<div class="rpt-desc-box">No custodian on file for this listing yet.</div>';
  return     '<div class="info-card-head"><h3>Pet information</h3></div>' +
    '<div class="info-grid">' +
      infoCell('Pet ID', p.ref || '—') +
      infoCell('Species', p.species || '—') +
      infoCell('Breed', p.breed || '—') +
      infoCell('Sex', p.gender || '—') +
      infoCell('Age', ageLabel(p.age)) +
      infoCell('Color / Markings', p.color || '—') +
      infoCell('Weight', p.weight || '—') +
      infoCell('Published', fmtDate(p.created_at)) +
      infoCell('Last Updated', p.updated_at ? fmtDateTime(p.updated_at) : '—') +
      infoCell('Public Visibility', p.status === 'withdrawn' ? 'Hidden' : 'Public') +
    '</div>' +
    '<div style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:14px;font-weight:600;color:#18181b;margin:18px 0 10px;">Adoption readiness</div>' +
    '<table class="readiness-table"><thead><tr><th>Check</th><th>Status</th></tr></thead><tbody>' +
      '<tr><td>Vaccination Status</td><td>' + esc(vm.label) + '</td></tr>' +
      '<tr><td>Health Status</td><td>' + health + '</td></tr>' +
      '<tr><td>Ready for Adoption</td><td>' + (ready ? 'Yes' : 'No') + '</td></tr>' +
    '</tbody></table>' +
    '<div style="font-family:\'Inter\',\'Segoe UI\',sans-serif;font-size:14px;font-weight:600;color:#18181b;margin:18px 0 10px;">Current Custodian</div>' + cust;
}

function listingTabHtml(p){
  const lm = listingMeta(p.status);
  const title = p.name + ' — ' + cap(p.species) + ' ' + (p.gender || '');
  return '<div class="listing-tab">' +
    '<div class="info-card-head"><h3>Public Listing</h3></div>' +
    '<div class="info-rows">' +
      infoRow('check', 'Listing Status', '<span class="status-pill ' + lm.cls + '">' + esc(lm.label) + '</span>', true) +
      infoRow('cal', 'Published', fmtDate(p.created_at)) +
      infoRow('tag', 'Listing Title', title) +
      infoRow('users', 'Public Visibility', p.status === 'withdrawn' ? 'Hidden — not shown on the adoption page' : 'Public — visible on the adoption page') +
      infoRow('clock', 'Last Updated', p.updated_at ? fmtDateTime(p.updated_at) : '—') +
    '</div>' +
    '<div class="rpt-section-title">Description</div>' +
    '<div class="rpt-desc-box">' + esc(p.description || 'No description provided.') + '</div>' +
  '</div>';
}

function historyTabHtml(p){
  const evts = buildHistory(p);
  if(!evts.length){
    return '<div class="rpt-section-title">Activity Timeline</div>' +
      '<div class="no-results"><i class="fas fa-history"></i><p>No activity yet.</p></div>';
  }
  return '<div class="rpt-section-title">Activity Timeline</div>' +
    '<ul class="rpt-history">' + evts.map(function(e){
      return '<li>' + esc(e.text) + '<span class="h-date">' + fmtDateTime(e.at) + '</span></li>';
    }).join('') + '</ul>';
}
function buildHistory(p){
  const evts = [];
  const lastAt = p.updated_at || p.created_at;
  if(p.created_at) evts.push({ text: 'Listed for adoption', at: p.created_at });
  if(p.updated_at && p.updated_at !== p.created_at) evts.push({ text: 'Listing details updated', at: p.updated_at });
  if(/vaccinated/i.test(p.vaccination) && !/not/i.test(p.vaccination)) evts.push({ text: 'Vaccination recorded — ' + p.vaccination, at: lastAt });
  if(p.adopted_at) evts.push({ text: 'Marked as adopted', at: p.adopted_at });
  if(p.status === 'withdrawn') evts.push({ text: 'Listing withdrawn', at: lastAt });
  evts.sort(function(a,b){ return new Date(b.at) - new Date(a.at); });
  return evts;
}

/* ───────────────────────── Quick status actions ───────────────────────── */
function markAdopted(id){ askConfirm('Mark as Adopted', 'Confirm that this pet has been adopted by a family?', 'Mark Adopted', function(){ setPetStatus(id, 'adopted'); }); }
function unpublishPet(id){ askConfirm('Unpublish Listing', 'This will hide the pet from the public adoption page.', 'Unpublish', function(){ setPetStatus(id, 'withdrawn'); }); }
function relistPet(id){ askConfirm('Re-list Pet', 'Make this pet available again on the adoption page?', 'Re-list', function(){ setPetStatus(id, 'available'); }); }

function setPetStatus(id, status){
  const fd = new FormData();
  fd.append('action', 'set_status');
  fd.append('id', id);
  fd.append('status', status);
  fetch('staff-pets-handler.php', { method: 'POST', body: fd })
    .then(function(res){ return res.json(); })
    .then(function(data){
      if(data.success){
        const p = DATA.find(x => x.id === id);
        if(p) p.status = data.status;
        render();
        renderCounts();
        if(state.selected === id) renderDetail();
        toast(data.message || 'Listing updated', 'success');
      } else {
        toast(data.message || 'Update failed', 'error');
      }
    })
    .catch(function(){ toast('Network error', 'error'); });
}

/* ───────────────────────── Confirm modal ───────────────────────── */
let confirmCb = null;
let deleteId = 0;
function askConfirm(title, msg, btnLabel, cb){
  document.getElementById('confirmTitle').innerHTML = esc(title);
  document.getElementById('confirmMsg').textContent = msg;
  document.getElementById('confirmYesBtn').innerHTML = esc(btnLabel);
  confirmCb = cb;
  openModal('confirmModal');
}
function confirmYes(){
  if(confirmCb) confirmCb();
  confirmCb = null;
  closeModal('confirmModal');
}

function confirmDelete(id){
  deleteId = id;
  askConfirm('Delete Listing', 'Are you sure you want to delete this pet listing permanently? This cannot be undone.', 'Delete', executeDelete);
}
function executeDelete(){
  if(!deleteId) return;
  const fd = new FormData();
  fd.append('action', 'delete');
  fd.append('id', deleteId);
  fetch('staff-pets-handler.php', { method: 'POST', body: fd })
    .then(function(res){ return res.json(); })
    .then(function(data){
      if(data.success){
        const idx = DATA.findIndex(x => x.id === deleteId);
        if(idx !== -1) DATA.splice(idx, 1);
        if(state.selected === deleteId) closeProfile();
        render();
        renderCounts();
        toast('Pet listing deleted', 'success');
      } else {
        toast(data.message || 'Delete failed', 'error');
      }
      deleteId = 0;
    })
    .catch(function(){ toast('Network error', 'error'); deleteId = 0; });
}

/* ───────────────────────── Toast ───────────────────────── */
let toastTimer = null;
function toast(msg, type){
  const el = document.getElementById('cpdToast');
  el.className = 'cpd-toast ' + (type || 'success');
  el.innerHTML = '<i class="fas ' + (type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle') + '"></i><span>' + esc(msg) + '</span>';
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(function(){ el.classList.remove('show'); }, 2600);
}

/* ───────────────────────── Create / edit modal ───────────────────────── */
function previewImage(input, previewId, areaId){
  const preview = document.getElementById(previewId);
  const area = document.getElementById(areaId);
  const placeholder = area.querySelector('.upload-placeholder');
  if(input.files && input.files[0]){
    const reader = new FileReader();
    reader.onload = function(e){
      preview.src = e.target.result;
      preview.style.display = 'block';
      if(placeholder) placeholder.style.display = 'none';
      area.classList.add('has-image');
    };
    reader.readAsDataURL(input.files[0]);
  }
}

function openCreate(){
  document.getElementById('modalTitle').textContent = 'Add New Pet';
  document.getElementById('modalDescription').textContent = 'Fill in the pet\'s details. Click save when you\'re done.';
  document.getElementById('submitBtnText').textContent = 'Publish Pet Listing';
  document.getElementById('formId').value = '0';
  document.getElementById('formCurrentPhoto').value = '';
  document.getElementById('petForm').reset();
  document.getElementById('photoPreview').style.display = 'none';
  document.getElementById('photoPlaceholder').style.display = '';
  document.getElementById('photoArea').classList.remove('has-image');
  openModal('petModal');
}

function openEdit(id){
  document.getElementById('modalTitle').textContent = 'Edit Pet';
  document.getElementById('modalDescription').textContent = 'Make changes to the pet\'s details here. Click save when you\'re done.';
  document.getElementById('submitBtnText').textContent = 'Update';

  fetch('staff-pets-handler.php?action=get&id=' + id)
    .then(function(res){ return res.json(); })
    .then(function(data){
      if(data.success){
        const p = data.data;
        document.getElementById('formId').value = p.listing_id;
        document.getElementById('formCurrentPhoto').value = p.photo_url || '';
        document.getElementById('formName').value = p.name;
        document.getElementById('formSpecies').value = p.species;
        document.getElementById('formBreed').value = p.breed;
        document.getElementById('formAge').value = p.age;
        document.getElementById('formGender').value = p.gender;
        document.getElementById('formWeight').value = p.weight;
        document.getElementById('formColor').value = p.color;
        document.getElementById('formRescued').value = p.rescued_date;
        document.getElementById('formDesc').value = p.description;
        document.getElementById('formStatus').value = p.status;

        if(p.photo_url){
          const preview = document.getElementById('photoPreview');
          preview.src = p.photo_url;
          preview.style.display = 'block';
          document.getElementById('photoPlaceholder').style.display = 'none';
          document.getElementById('photoArea').classList.add('has-image');
        }
      } else {
        alert('Failed to load data: ' + data.message);
      }
    })
    .catch(function(){ alert('Network error loading data'); });

  openModal('petModal');
}

document.getElementById('petForm').addEventListener('submit', function(e){
  e.preventDefault();
  const formData = new FormData(this);
  const btn = e.submitter && e.submitter.matches('button[type="submit"]') ? e.submitter : this.querySelector('button[type="submit"]');
  const orig = btn.innerHTML;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
  btn.disabled = true;

  fetch('staff-pets-handler.php', { method: 'POST', body: formData })
    .then(function(res){ return res.json(); })
    .then(function(data){
      if(data.success){
        closeModal('petModal');
        location.reload();
      } else {
        alert('Error: ' + data.message);
      }
    })
    .catch(function(){ alert('Network error'); })
    .finally(function(){
      btn.innerHTML = orig;
      btn.disabled = false;
    });
});

/* ───────────────────────── Init ───────────────────────── */
renderCounts();
render();
</script>

</body>
</html>