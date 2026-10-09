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
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
require_once 'db.php';
require_once 'includes/adoption-workflow.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'staff') {
    header('Location: login-staff.php');
    exit;
}

adoptionReleaseExpiredReservations($conn);
$adoptionApplications = [];
$applicationResult = $conn->query(
    "SELECT a.application_id, a.status, a.application_date, a.date_of_birth,
            a.residency_proof_file, a.requested_info,
            a.resident_response, a.info_deadline, a.approved_pickup_at, a.finalized_at,
            a.housing_type, a.landlord_permission,
            a.home_type, a.has_fenced_yard, a.household_members, a.has_other_pets,
            a.other_pets_details, a.pet_care_experience, a.reason_for_adoption,
            a.agreement_version, a.agreed_at, a.interview_at, a.home_visit_at, a.home_visit_notes,
            u.full_name, u.email, u.phone, b.barangay_name, p.listing_id AS pet_listing_id,
            p.name AS pet_name, p.species, p.breed, p.photo_url
     FROM adoption_applications a
     JOIN users u ON u.user_id = a.applicant_id
     LEFT JOIN barangays b ON b.barangay_id = u.barangay_id
     JOIN pet_listings p ON p.listing_id = a.pet_listing_id
     WHERE a.pet_listing_id IS NOT NULL
       AND a.status IN ('pending','under_review','needs_info','approved','adopted','expired')
     ORDER BY a.application_date ASC, a.application_id ASC"
);
if (!$applicationResult) {
    throw new RuntimeException('Open adoption applications could not be loaded.');
}
while ($row = $applicationResult->fetch_assoc()) {
    $adoptionApplications[] = $row;
}
$applicationResult->free();

$pageTitle = 'Adoption Pool';
$activePage = 'adoption-pool';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Adoption Pool - City Veterinary Services Office (DCVS)</title>

  <!-- DCVS System Fonts & Styles -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/css/dashboard.css?v=20260919" />
  <link rel="stylesheet" href="assets/css/responsive.css?v=20260919" />
  <style>
    .adoption-review-card{background:#fff;border:1px solid #e4e4e7;border-radius:8px;padding:16px 20px;margin:0 0 14px;box-shadow:0 1px 2px rgba(0,0,0,.05);font-family:'Inter','Segoe UI',sans-serif;color:#18181b}
    .adoption-review-summary{display:flex;align-items:center;gap:14px;min-width:0}
    .adoption-review-pet-photo{width:68px;height:68px;flex:0 0 68px;object-fit:cover;border-radius:8px;background:#f4f4f5}
    .adoption-review-summary-copy{min-width:0;flex:1}
    .adoption-review-pet-title{margin:0 0 3px;font-family:'Inter','Segoe UI',sans-serif;font-size:18px;font-weight:600;color:#18181b;line-height:1.35}
    .adoption-review-pet-meta{margin-left:6px;color:#71717a;font-size:12px;font-weight:400}
    .adoption-review-applicant,.adoption-review-submitted{margin:2px 0;color:#18181b;font-size:14px;font-weight:400;line-height:1.45}
    .adoption-review-submitted{color:#71717a;font-size:13px}
    .adoption-review-summary-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex:0 0 auto}
    .adoption-review-status{display:inline-flex;align-items:center;padding:3px 10px;border:1px solid #e4e4e7;border-radius:4px;font-family:'Inter','Segoe UI',sans-serif;font-size:12px;font-weight:500;line-height:1.4;white-space:nowrap}
    .adoption-review-status.pending{background:#fef3c7;color:#92400e;border-color:#fde68a}
    .adoption-review-status.active{background:#eff6ff;color:#1e40af;border-color:#bfdbfe}
    .adoption-review-status.needs-info{background:#fef3c7;color:#92400e;border-color:#fde68a}
    .adoption-review-toggle{min-width:130px;height:36px;padding:0 12px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font-family:inherit;font-size:14px;font-weight:500;cursor:pointer;white-space:nowrap;box-shadow:0 1px 2px rgba(0,0,0,.05);transition:background-color .15s ease,border-color .15s ease}
    .adoption-review-toggle:hover{background:#f4f4f5}
    .adoption-review-details{margin-top:18px}
    .adoption-review-details[hidden]{display:none}
    .adoption-review-details-title{margin:0 0 10px;color:#18181b;font-size:14px;font-weight:600;letter-spacing:0;text-transform:none}
    .adoption-review-checks{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px}
    .adoption-review-check{padding:5px 9px;border:1px solid #e4e4e7;border-radius:6px;background:#fafafa;color:#3f3f46;font-size:13px;font-weight:500}
    .adoption-review-check.pending{border-color:#e4e4e7;background:#f4f4f5;color:#71717a}
    .adoption-review-facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
    .adoption-review-fact{min-width:0;padding:10px 12px;border:1px solid #f4f4f5;border-radius:6px;background:#fafafa}
    .adoption-review-fact-label{display:block;margin-bottom:2px;color:#71717a;font-size:13px;font-weight:600;letter-spacing:0;text-transform:none}
    .adoption-review-fact-value{display:block;color:#18181b;font-size:15px;font-weight:400;line-height:1.4;white-space:pre-wrap;overflow-wrap:anywhere}
    .adoption-review-reason{margin-top:10px;padding:12px;border:1px solid #e4e4e7;border-radius:6px;background:#fff}
    .adoption-review-documents{margin:10px 0 0;font-size:13px}
    .adoption-review-documents a{color:#18181b;font-weight:500;text-decoration:underline;text-underline-offset:4px}
    .adoption-review-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px}
    .adoption-review-grid[hidden]{display:none}
    .adoption-review-grid label{display:block;font-size:13px;font-weight:500;color:#18181b;margin-bottom:5px}
    .adoption-review-grid input,.adoption-review-grid select,.adoption-review-grid textarea{box-sizing:border-box;width:100%;min-height:36px;border:1px solid #e4e4e7;border-radius:6px;padding:8px 12px;background:#fff;color:#18181b;font:14px 'Inter','Segoe UI',sans-serif;box-shadow:0 1px 2px rgba(0,0,0,.05);outline:none;transition:border-color .15s ease,box-shadow .15s ease}
    .adoption-review-grid input:focus,.adoption-review-grid select:focus,.adoption-review-grid textarea:focus{border-color:#18181b;box-shadow:0 0 0 1px #18181b}
    .adoption-review-grid textarea{min-height:76px;resize:vertical}
    .adoption-review-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;padding-top:10px;border-top:0}
    .adoption-review-actions button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:36px;padding:0 14px;border:1px solid #18181b;border-radius:6px;background:#18181b;color:#fafafa;font:500 14px 'Inter','Segoe UI',sans-serif;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.05);transition:background-color .15s ease,border-color .15s ease,color .15s ease}
    .adoption-review-actions button:hover{background:#27272a}
    .adoption-review-actions button.secondary{background:#fff;color:#18181b;border-color:#e4e4e7}
    .adoption-review-actions button.secondary:hover,.adoption-review-actions button.reject:hover{background:#f4f4f5}
    .adoption-review-actions button.reject{background:#fff;color:#dc2626;border-color:#fecaca}
    .adoption-review-actions button.confirm-reject{background:#dc2626;color:#fff;border-color:#dc2626}
    .adoption-review-actions button.confirm-reject:hover{background:#b91c1c;border-color:#b91c1c}
    .adoption-review-actions button:disabled{opacity:.5;cursor:not-allowed}
    .adoption-review-notice{margin:10px 0;padding:10px;border:1px solid #e4e4e7;border-radius:6px;background:#fafafa;color:#3f3f46;font-size:13px}
    .adoption-document-dialog,.adoption-action-dialog{position:fixed;inset:0;z-index:1200;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.65)}
    .adoption-document-dialog.open,.adoption-action-dialog.open{display:flex}
    .adoption-document-dialog-panel,.adoption-action-dialog-panel{position:relative;box-sizing:border-box;width:100%;max-width:900px;max-height:92vh;padding:20px;border:1px solid #e4e4e7;border-radius:8px;background:#fff;box-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1);font-family:'Inter','Segoe UI',sans-serif;color:#18181b}
    .adoption-document-dialog-panel{display:flex;flex-direction:column;gap:12px}
    .adoption-document-dialog-header,.adoption-action-dialog-footer{display:flex;align-items:center;justify-content:space-between;gap:12px}
    .adoption-document-dialog-title,.adoption-action-dialog-title{margin:0;color:#18181b;font-size:16px;font-weight:600}
    .adoption-document-dialog-close,.adoption-action-dialog-cancel,.adoption-action-dialog-confirm{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 14px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font:500 14px 'Inter','Segoe UI',sans-serif;cursor:pointer}
    .adoption-document-dialog-close:hover,.adoption-action-dialog-cancel:hover{background:#f4f4f5}
    .adoption-document-preview{width:100%;height:min(72vh,760px);border:1px solid #e4e4e7;border-radius:6px;background:#f4f4f5}
    .adoption-action-dialog{z-index:1210}
    .adoption-action-dialog-panel{max-width:440px;padding:24px}
    .adoption-action-dialog-description{margin:8px 0 20px;color:#71717a;font-size:14px;line-height:1.5}
    .adoption-action-schedule-field{margin:0 0 18px}
    .adoption-action-schedule-field[hidden]{display:none}
    .adoption-action-schedule-field label{display:block;margin-bottom:6px;color:#18181b;font-size:13px;font-weight:500}
    .adoption-action-schedule-field input{box-sizing:border-box;width:100%;height:36px;padding:0 10px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font:14px 'Inter','Segoe UI',sans-serif;outline:none}
    .adoption-action-schedule-field input:focus{border-color:#18181b;box-shadow:0 0 0 1px #18181b}
    .adoption-action-dialog-footer{justify-content:flex-end}
    .adoption-action-dialog-confirm{border-color:#18181b;background:#18181b;color:#fafafa}
    .adoption-action-dialog-confirm:hover{background:#27272a}
    .adoption-action-dialog-confirm.danger{border-color:#dc2626;background:#dc2626}
    .adoption-action-dialog-confirm.danger:hover{border-color:#b91c1c;background:#b91c1c}
    @media(max-width:850px){.adoption-review-facts{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:700px){.adoption-review-card{padding:14px}.adoption-review-summary{align-items:flex-start;flex-wrap:wrap}.adoption-review-summary-actions{flex-wrap:wrap;justify-content:flex-end;margin-left:auto}.adoption-review-grid,.adoption-review-facts{grid-template-columns:1fr}.adoption-review-toggle{min-width:0}.adoption-review-pet-photo{width:56px;height:56px;flex-basis:56px}.adoption-document-dialog-panel{padding:14px}.adoption-document-preview{height:75vh}}
  </style>

  <style>
    /* ==========================================================================
       DESIGN SYSTEM & THEME TOKENS
       ========================================================================== */
    :root {
      --green: #045e1f;
      --green-dark: #043012;
      --green-light: #f0fdf4;
      --red: #dc2626;
      --blue: #2563eb;
      --amber: #f59e0b;
      --border: #eef2f6;
      --border-2: #e2e8f0;
      --text: #0f172a;
      --text-2: #475569;
      --text-3: #94a3b8;
      --bg: #f8fafc;

      --primary-green: #045e1f;
      --primary-green-hover: #043012;
      --primary-green-light: #f0fdf4;
      --primary-green-soft: #D1EEDB;
      --primary-green-text: #0E6231;
      --accent-green: #057b2d;

      --bg-page: #f8fafc;
      --bg-card: #ffffff;
      --bg-subtle: #f1f5f9;
      --bg-hover: #f8fafc;

      --border-color: #e2e8f0;
      --border-color-light: #f1f5f9;
      --border-color-dark: #cbd5e1;

      --text-main: #0f172a;
      --text-secondary: #475569;
      --text-muted: #64748b;
      --text-faint: #94a3b8;

      --white: #ffffff;
      --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1);
      --ink-700: #334155;
      --ink-50: #f8fafc;

      /* Status Pill Colors */
      --badge-green-bg: #f0fdf4;
      --badge-green-text: #166534;
      --badge-green-border: #bbf7d0;

      --badge-blue-bg: #eff6ff;
      --badge-blue-text: #1e40af;
      --badge-blue-border: #bfdbfe;

      --badge-amber-bg: #fffbeb;
      --badge-amber-text: #92400e;
      --badge-amber-border: #fde68a;

      --badge-slate-bg: #f4f4f5;
      --badge-slate-text: #52525b;
      --badge-slate-border: #e4e4e7;

      --badge-purple-bg: #faf5ff;
      --badge-purple-text: #7e22ce;
      --badge-purple-border: #e9d5ff;

      --radius: 14px;
      --radius-sm: 8px;
      --radius-md: 10px;
      --radius-lg: 14px;
      --radius-xl: 18px;
      --shadow: 0 1px 3px rgba(0,0,0,0.04);
      --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
      --shadow-card: 0 1px 3px rgba(0,0,0,0.04);
      --shadow-lg: 0 8px 32px rgba(0,0,0,0.08);
      --shadow-modal: 0 25px 80px rgba(0,0,0,0.15);

      --transition: all 0.2s ease;
    }

    *, *::before, *::after {
      box-sizing: border-box;
    }

    /* ── Topbar & Modal (from dashboard.css / staff-sidebar.php) ── */
    .topbar {
      position: fixed !important;
      top: 0;
      left: var(--sidebar-w, 270px);
      right: 0;
      height: var(--topbar-h, 64px);
      background: #fff;
      border-bottom: 1px solid var(--border, #e4e4e7);
      display: flex;
      align-items: center;
      gap: 1rem;
      padding: 0 1.5rem;
      z-index: 100;
      transition: left 0.2s ease;
    }
    body.sb2-active .topbar { left: var(--sidebar-w) !important; }
    .main-content {
      margin-left: var(--sidebar-w, 270px);
      margin-top: var(--topbar-h, 64px);
      padding: 1.75rem 2rem;
      min-height: calc(100vh - var(--topbar-h, 64px));
      background: #ffffff;
    }
    .modal {
      display: none !important;
      position: fixed;
      inset: 0;
      z-index: 999;
      background: rgba(0,0,0,0.5);
      align-items: center;
      justify-content: center;
      padding: 1rem;
    }
    .modal.open,
    .modal.show,
    #logoutModal.modal.active {
      display: flex !important;
    }

    body {
      background-color: var(--bg-page);
      color: var(--text-main);
      font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
      font-size: 14px;
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      min-height: 100vh;
    }

    /* ==========================================================================
       MAIN WRAPPER
       ========================================================================== */
    .main-wrapper {
      width: 100%;
      min-width: 0;
    }

    /* ==========================================================================
       PAGE ACTION TOOLBAR & BUTTONS
       ========================================================================== */
    .page-action-toolbar {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 8px;
      margin-bottom: 6px;
    }

    /* Action Buttons */
    .btn-secondary-action {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      height: 36px;
      padding: 0 16px;
      border-radius: 6px;
      border: 1px solid #e4e4e7;
      background: #ffffff;
      color: #18181b;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 500;
      line-height: 1;
      white-space: nowrap;
      cursor: pointer;
      box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
      transition: background-color 0.15s ease, border-color 0.15s ease;
    }

    .btn-secondary-action:hover {
      background: #f4f4f5;
    }

    .btn-secondary-action svg {
      width: 15px;
      height: 15px;
    }

    /* Rescue Dropdown */
    .rescue-dropdown-wrap {
      position: relative;
      display: inline-flex;
    }

    .rescue-dropdown-menu {
      display: none;
      position: absolute;
      top: calc(100% + 4px);
      left: 0;
      z-index: 100;
      min-width: 220px;
      background: #ffffff;
      border: 1px solid #e4e4e7;
      border-radius: 8px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.1);
      padding: 4px;
      animation: dropIn 0.12s ease;
    }

    .rescue-dropdown-menu.show {
      display: block;
    }

    @keyframes dropIn {
      from { opacity: 0; transform: translateY(-4px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .rescue-dropdown-item {
      display: flex;
      align-items: center;
      gap: 10px;
      width: 100%;
      padding: 8px 10px;
      border: none;
      background: none;
      border-radius: 6px;
      cursor: pointer;
      text-align: left;
      transition: background 0.12s;
    }

    .rescue-dropdown-item:hover {
      background: #f4f4f5;
    }

    .rescue-dropdown-icon {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 28px;
      height: 28px;
      border-radius: 6px;
      background: #f4f4f5;
      color: #52525b;
      flex-shrink: 0;
    }

    .rescue-dropdown-text {
      display: flex;
      flex-direction: column;
      gap: 1px;
    }

    .rescue-dropdown-label {
      font-family: 'Inter', sans-serif;
      font-size: 13px;
      font-weight: 500;
      color: #18181b;
    }

    .rescue-dropdown-desc {
      font-family: 'Inter', sans-serif;
      font-size: 11px;
      color: #a1a1aa;
    }

    .btn-primary-action {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      height: 36px;
      padding: 0 16px;
      border-radius: 6px;
      border: 1px solid #18181b;
      background: #18181b;
      color: #fafafa;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 500;
      line-height: 1;
      white-space: nowrap;
      cursor: pointer;
      box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
      transition: background-color 0.15s ease;
    }

    .btn-primary-action:hover {
      background: #27272a;
    }

    .btn-primary-action svg {
      width: 15px;
      height: 15px;
    }

    /* ==========================================================================
       PAGE CONTENT CONTAINER
       ========================================================================== */
    .pool-body {
      width: calc(100% + 4rem);
      max-width: none;
      margin: -1.75rem -2rem;
      padding: 18px;
      box-sizing: border-box;
      background: var(--white);
      display: flex;
      flex-direction: column;
      gap: 16px;
      min-height: calc(100vh - 120px);
    }

    /* ==========================================================================
       TOP SUMMARY CARDS — reuse .kpi-card / .kpis from dashboard.css
       ========================================================================== */
    .kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:0; }

    .kpi-card { display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:14px 16px; background:#fff; border:1px solid #E5E7EB; border-radius:14px; box-shadow:0 1px 3px rgba(16,24,40,.05),0 1px 2px rgba(16,24,40,.03); transition:all .2s cubic-bezier(.4,0,.2,1); cursor:pointer; position:relative; }

    .kpi-card:hover { border-color:#D1D5DB; box-shadow:0 4px 12px -2px rgba(0,0,0,.05),0 2px 4px -1px rgba(0,0,0,.03); }

    .kpi-card.active-filter { border-color:var(--green); background:#FAFCFA; box-shadow:0 0 0 2px rgba(4,94,31,0.15); }

    .kpi-label { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; color:#18181b; margin:0 0 6px; }
    .kpi-value { font-family:'Inter','Segoe UI',sans-serif; font-size:26px; font-weight:600; color:#000000; letter-spacing:-0.02em; line-height:1; margin:0 0 6px; }
    .kpi-sub { font-family:'Inter','Segoe UI',sans-serif; font-size:14px; color:#71717a; }



    /* ==========================================================================
       WORKFLOW INFO BANNER
       ========================================================================== */
    .workflow-strip {
      display: flex;
      align-items: center;
      gap: 14px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      color: #18181b;
    }

    .workflow-flow {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .workflow-step {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 400;
      color: #18181b;
    }

    .workflow-step.available {
      color: #18181b;
    }

    .workflow-step.not-listed {
      color: #18181b;
    }

    .workflow-step.create-listing {
      color: #18181b;
    }

    .workflow-step.published {
      color: #18181b;
    }

    .workflow-step.adopted {
      color: #18181b;
    }

    .workflow-arrow {
      color: #71717a;
      font-size: 14px;
      font-weight: 500;
    }

    /* ==========================================================================
       POOL TABS
       ========================================================================== */
    .pool-tabs {
      display: flex;
      gap: 0;
      margin-bottom: 8px;
    }

    .pool-tab {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 500;
      color: #71717a;
      background: none;
      border: none;
      padding: 10px 20px;
      cursor: pointer;
      border-bottom: 2px solid transparent;
      margin-bottom: -2px;
      transition: color 0.15s, border-color 0.15s;
    }

    .pool-tab:hover {
      color: #18181b;
    }

    .pool-tab.active {
      color: #18181b;
      font-weight: 600;
      border-bottom-color: #18181b;
    }

    .tab-panel {
      display: none;
      margin-top: -8px;
    }

    .tab-panel.active {
      display: flex;
      flex-direction: column;
      flex: 1 1 auto;
      min-height: 0;
    }

    /* ==========================================================================
       TABLE CONTAINER & FILTERS
       ========================================================================== */
    .table-panel {
      background: #FFFFFF;
      display: flex;
      flex-direction: column;
      flex: 1 1 auto;
      min-height: 0;
      overflow: visible;
    }
    .table-panel .table-responsive { flex: 1 1 auto; min-height: var(--staff-table-min-height); }
    .table-panel > .dt-pagination { flex: 0 0 auto; margin-top:0; border-top:1px solid #e4e4e7; background:#fff; }

    /* Filters Bar */
    .table-filter-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      flex-wrap: wrap;
      background: transparent;
      border-bottom: none;
      padding: 0;
      margin-bottom: 12px;
    }

    .filter-left-controls {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .table-filter-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-left: auto;
    }

    /* Search Box */
    .search-grow-wrap {
      position: relative;
      flex: 0 1 auto;
      width: 300px;
      min-width: 180px;
    }

    .search-grow-wrap svg {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #71717a;
      width: 15px;
      height: 15px;
      pointer-events: none;
    }

    .table-search-input {
      height: 36px;
      width: 100%;
      padding: 0 12px 0 36px;
      border-radius: 6px;
      border: 1px solid #e4e4e7;
      background: #fff;
      font-size: 14px;
      color: #18181b;
      outline: none;
      font-family: inherit;
    }

    .table-search-input:focus {
      border-color: #18181b;
      box-shadow: 0 0 0 1px #18181b;
    }

    .table-search-input::placeholder {
      color: #71717a;
    }

    /* Filter Selects */
    .table-filter-select {
      height: 36px;
      padding: 0 26px 0 10px;
      border-radius: 6px;
      border: 1px solid #e4e4e7;
      background-color: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 10px center;
      font-size: 14px;
      font-weight: 500;
      color: #18181b;
      font-family: inherit;
      cursor: pointer;
      box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
      appearance: none;
      -webkit-appearance: none;
      transition: border-color .15s, box-shadow .15s;
    }

    .table-filter-select:hover {
      border-color: #cbd5e1;
    }

    .table-filter-select:focus {
      outline: none;
      border-color: #18181b;
      box-shadow: 0 0 0 1px #18181b;
    }

    .btn-reset-filters {
      height: 36px;
      padding: 0 12px;
      border: 1px solid #e4e4e7;
      background: transparent;
      border-radius: 6px;
      font-size: 13px;
      font-weight: 500;
      color: #71717a;
      cursor: pointer;
      font-family: inherit;
      box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
      transition: background-color .15s, border-color .15s, color .15s;
      display: none;
    }

    .btn-reset-filters:hover {
      background: #f4f4f5;
      color: #18181b;
      border-color: #d4d4d8;
    }

    .filter-count-badge {
      font-size: 12px;
      font-weight: 600;
      color: var(--text-faint);
      white-space: nowrap;
    }

    /* ==========================================================================
       DATA TABLE — exact copy from #reports-all in dashboard-staff.php
       ========================================================================== */
    .table-responsive {
      overflow: hidden;
      border: 1px solid #e4e4e7;
      border-radius: 8px;
      background: #fff;
      min-height: var(--staff-table-min-height);
    }

    .data-table {
      width: 100%;
      border-collapse: collapse;
      color: #18181b !important;
      font-size: 14px !important;
    }

    .data-table thead tr {
      border-bottom: 1px solid #e4e4e7 !important;
    }

    .data-table thead th {
      height: 40px !important;
      padding: 0 8px !important;
      text-align: left !important;
      vertical-align: middle !important;
      font-size: 14px !important;
      font-weight: 500 !important;
      color: #71717a !important;
      white-space: nowrap !important;
      background: #fafafa !important;
      border-bottom: none !important;
      text-transform: none !important;
      letter-spacing: normal !important;
    }

    .data-table thead th.sd-th-action {
      text-align: right !important;
    }

    /* Sort buttons — exact copy from #reports-all */
    .th-sort {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      height: 28px;
      margin-left: -12px;
      padding: 0 12px;
      border-radius: 6px;
      background: transparent;
      border: none;
      font-size: 14px;
      font-weight: 500;
      color: #71717a;
      cursor: pointer;
      white-space: nowrap;
      font-family: inherit;
    }

    .th-sort:hover {
      background: #f4f4f5;
      color: #18181b;
    }

    .th-sort.th-sorted {
      color: #18181b;
    }

    .th-sort-icon {
      width: 16px;
      height: 16px;
      color: #71717a;
      flex-shrink: 0;
    }

    .th-sorted .th-sort-icon {
      color: #18181b;
    }

    .data-table tbody tr {
      border-bottom: 1px solid #e4e4e7;
      transition: background 0.15s;
      cursor: default;
    }

    .data-table tbody tr.pool-clickable-row {
      cursor: pointer;
    }

    .data-table tbody tr.pool-clickable-row:focus-visible {
      outline: 2px solid #2563eb;
      outline-offset: -2px;
    }

    .data-table tbody tr:last-child {
      border-bottom: none;
    }

    .data-table tbody tr:hover {
      background: #fafafa !important;
    }

    .data-table tbody tr:hover td {
      background: #fafafa !important;
    }

    .data-table tbody td {
      padding: 8px !important;
      vertical-align: middle !important;
      font-size: 14px !important;
      color: #18181b !important;
    }

    .data-table tbody td.sd-td-action {
      text-align: right;
    }

    /* Checkbox bulk selection (same pattern as All Reports > Barangay Portal) */
    .sd-table-cb {
      width: 16px;
      height: 16px;
      accent-color: #18181b;
      cursor: pointer;
    }

    .bulk-bar {
      display: none;
      align-items: center;
      gap: 12px;
      margin-bottom: 4px;
      padding-top: 10px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
    }

    .bulk-bar.active {
      display: flex;
    }

    .bulk-bar .bulk-count {
      font-size: 14px;
      color: #71717a;
      white-space: nowrap;
      margin-right: 4px;
    }

    .bulk-bar .bulk-actions {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border: 1px solid #e4e4e7;
      border-radius: 6px;
      background: #fff;
    }

    .bulk-bar .bulk-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 5px;
      height: 30px;
      padding: 0 12px;
      border-radius: 5px;
      border: none;
      background: transparent;
      font-family: inherit;
      font-size: 13px;
      font-weight: 500;
      color: #52525b;
      cursor: pointer;
    }

    .bulk-bar .bulk-btn:hover {
      background: #f4f4f5;
      color: #18181b;
    }

    .bulk-bar .bulk-btn.danger {
      color: #dc2626;
    }

    .bulk-bar .bulk-btn.danger:hover {
      background: #fef2f2;
    }

    .bulk-bar .bulk-btn svg {
      width: 14px;
      height: 14px;
      flex-shrink: 0;
    }

    /* Pet Profile Cell */
    .pet-cell {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .pet-thumbnail {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      object-fit: cover;
      background: #e4e4e7;
      flex-shrink: 0;
    }

    .pet-meta-text {
      display: flex;
      flex-direction: column;
    }

    .pet-name-title {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 400;
      color: #18181b;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .pet-sub-meta {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 12px;
      font-weight: 400;
      color: #71717a;
      margin-top: 2px;
    }

    /* Status Pills — exact copy from .cpd .status-pill */
    .status-pill {
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

    .status-pill.available {
      background: #ECFDF3;
      color: #027A48;
      border-color: #D1FADF;
    }

    .status-pill.pending {
      background: #FEF3C7;
      color: #92400E;
      border-color: #FDE68A;
    }

    .status-pill.adopted {
      background: #dbeafe;
      color: #1e40af;
      border-color: #bfdbfe;
    }

    .status-pill.reserved {
      background: #fef3c7;
      color: #92400e;
      border-color: #fde68a;
    }

    .status-pill.published {
      background: #dbeafe;
      color: #1e40af;
      border-color: #bfdbfe;
    }

    .status-pill.not-listed {
      background: #f4f4f5;
      color: #71717a;
      border-color: #e4e4e7;
    }

    /* Listing Pills */
    .listing-pill {
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

    .listing-pill.published {
      background: #dbeafe;
      color: #1e40af;
      border-color: #bfdbfe;
    }

    .listing-pill.not-listed {
      background: #f1f5f9;
      color: #475569;
      border-color: #e2e8f0;
    }

    /* Three-dot row actions — exact copy from #reports-all */
    .sd-row-actions{ width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; background:transparent; border:none; color:#71717a; cursor:pointer; }
    .sd-row-actions:hover{ background:#f4f4f5; color:#18181b; }

    #poolRowMenuPop, .cpd .row-menu-pop{ position:fixed!important; z-index:9999!important; background:#ffffff!important; border:1px solid #e6e9ee!important; border-radius:10px!important; box-shadow:0 4px 14px rgba(15,23,32,.06)!important; padding:6px!important; min-width:190px!important; display:none; }
    #poolRowMenuPop.open, .cpd .row-menu-pop.open{ display:block!important; }
    #poolRowMenuPop .row-menu-item, .cpd .row-menu-item{ display:flex!important; align-items:center!important; gap:10px!important; width:100%!important; padding:9px 10px!important; border:none!important; background:none!important; border-radius:8px!important; font-family:'Inter','Segoe UI',system-ui,sans-serif!important; font-size:13px!important; font-weight:500!important; color:#334155!important; cursor:pointer!important; text-align:left!important; line-height:1.4!important; }
    #poolRowMenuPop .row-menu-item:hover, .cpd .row-menu-item:hover{ background:#f8fafc!important; }
    #poolRowMenuPop .row-menu-item.danger, .cpd .row-menu-item.danger{ color:#dc2626!important; }
    #poolRowMenuPop .row-menu-item.danger:hover, .cpd .row-menu-item.danger:hover{ background:#fef2f2!important; }
    #poolRowMenuPop .row-menu-item svg, .cpd .row-menu-item svg{ flex-shrink:0!important; width:15px!important; height:15px!important; }

    /* Empty state */
    .table-empty-state {
      text-align: center;
      padding: 48px 12px;
      color: #94a3b8;
    }

    .table-empty-state svg {
      width: 40px;
      height: 40px;
      margin-bottom: 10px;
      color: #cbd5e1;
    }

    .table-empty-state p {
      margin: 0;
      font-size: 13px;
    }

    /* Pagination — matching #client-pets .dt-pagination */
    .dt-pagination { display:flex; align-items:center; justify-content:flex-end; gap:12px; flex-wrap:wrap; padding:12px 8px; margin-top:auto; }
    .dt-pagination-right { display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
    .dt-rows { display:flex; align-items:center; gap:8px; font-size:14px; color:#71717a; }
    .dt-rows select { height:32px; width:70px; border-radius:6px; border:1px solid #e4e4e7; background:#fff; padding:0 8px; font-size:14px; color:#18181b; cursor:pointer; font-family:inherit; }
    .dt-rows select:focus { outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    .dt-page-label { width:110px; text-align:center; font-size:14px; font-weight:500; color:#18181b; white-space:nowrap; }
    .dt-page-btns { display:flex; align-items:center; gap:4px; }
    .dt-page-btn { height:32px; width:32px; display:inline-flex; align-items:center; justify-content:center; border-radius:6px; border:1px solid #e4e4e7; background:#fff; color:#18181b; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .dt-page-btn:hover:not(:disabled) { background:#f4f4f5; }
    .dt-page-btn:disabled { opacity:0.5; cursor:not-allowed; }
    .drawer-footer .btn-cancel,
    .drawer-footer .btn-toggle-publish { flex:1 1 0; min-width:0; }
    .drawer-footer .sd-btn{ height:36px; padding:0 16px; border-radius:6px; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:500; line-height:1; display:inline-flex; align-items:center; justify-content:center; gap:8px; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.05); transition:background-color .15s ease,border-color .15s ease,color .15s ease; }
    .drawer-footer .sd-btn-default{ background:#18181b; color:#fafafa; border:1px solid #18181b; }
    .drawer-footer .sd-btn-default:hover{ background:#27272a; }
    .drawer-footer .sd-btn-outline{ background:#fff; color:#18181b; border:1px solid #e4e4e7; }
    .drawer-footer .sd-btn-outline:hover{ background:#f4f4f5; }
    .drawer-footer .sd-btn:disabled{ opacity:.5; cursor:not-allowed; pointer-events:none; }

    /* ==========================================================================
       ADOPTION POOL SUMMARY SECTION (AFTER TABLE)
       ========================================================================== */

    /* ==========================================================================
       MODAL: ADD TO ADOPTION POOL (sd-dialog shadcn style)
       ========================================================================== */
    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.8);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      padding: 1rem;
    }

    .modal-backdrop.show {
      display: flex;
    }

    @keyframes modalIn {
      from { opacity: 0; transform: scale(0.95); }
      to { opacity: 1; transform: scale(1); }
    }

    .modal-window {
      position: relative;
      width: 100%;
      max-width: 560px;
      background: #ffffff;
      border: 1px solid #e4e4e7;
      border-radius: 8px;
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1);
      animation: modalIn 0.2s ease;
      max-height: 90vh;
      overflow-y: auto;
    }

    #record-rescue-modal .modal-window {
      max-width: 640px;
    }

    @media (max-width: 560px) {
      #record-rescue-modal .modal-window {
        max-width: 100%;
        margin: 8px;
      }
    }

    .modal-head {
      display: flex;
      flex-direction: column;
      gap: 6px;
      text-align: left;
    }

    .modal-title {
      margin: 0;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 18px;
      font-weight: 600;
      line-height: 1;
      letter-spacing: -0.025em;
      color: #18181b;
    }

    .modal-subtitle {
      margin: 0;
      font-size: 14px;
      color: #71717a;
      line-height: 1.5;
    }

    .btn-modal-close {
      position: absolute;
      top: 16px;
      right: 16px;
      width: 24px;
      height: 24px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 4px;
      border: none;
      background: transparent;
      color: #71717a;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .btn-modal-close:hover {
      background: #f4f4f5;
      color: #18181b;
    }

    .modal-form-body {
      display: flex;
      flex-direction: column;
      gap: 8px;
      overflow-x: hidden;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .form-label {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 13px;
      font-weight: 500;
      line-height: 1;
      color: #18181b;
    }

    .form-label span.req {
      color: #DC2626;
    }

    .form-input, .form-select, .form-textarea {
      width: 100%;
      height: 36px;
      padding: 0 12px;
      border: 1px solid #e4e4e7;
      border-radius: 6px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      color: #18181b;
      background: #fff;
      box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
      outline: none;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
      box-sizing: border-box;
    }

    .form-input::placeholder, .form-textarea::placeholder {
      color: #a1a1aa;
    }

    .form-input:focus, .form-select:focus, .form-textarea:focus {
      border-color: #18181b;
      box-shadow: 0 0 0 1px #18181b;
    }

    .form-select {
      appearance: none;
      -webkit-appearance: none;
      background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2371717a' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 12px center;
      padding-right: 36px;
      cursor: pointer;
    }

    .form-textarea {
      height: auto;
      min-height: 96px;
      padding: 10px 12px;
      resize: vertical;
      line-height: 1.5;
    }

    /* Enhanced Add to Adoption Pool Modal UI */
    .modal-section-label {
      font-size: 13px;
      font-weight: 600;
      color: #18181b;
      margin-bottom: 8px;
    }

    .checkbox-inline {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      font-size: 14px;
      font-weight: 500;
      color: #18181b;
      cursor: pointer;
      margin-top: 5px;
      user-select: none;
    }

    .checkbox-inline input[type="checkbox"] {
      width: 15px;
      height: 15px;
      accent-color: #045e1f;
      cursor: pointer;
    }

    .form-grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px 12px;
    }

    .form-grid-4 {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr 1fr;
      gap: 12px;
    }

    /* Rescue Batches & Records Section */
    .batch-section {
      margin-bottom: 12px;
    }

    .batch-section-header {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 12px;
    }

    .batch-section-title {
      font-family: 'Inter', sans-serif;
      font-size: 16px;
      font-weight: 600;
      color: #18181b;
    }

    .batch-section-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 20px;
      height: 20px;
      padding: 0 6px;
      border-radius: 10px;
      background: #f4f4f5;
      color: #000000;
      font-family: 'Inter', sans-serif;
      font-size: 11px;
      font-weight: 600;
    }

    .batch-section-empty {
      display: none;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: #fafafa;
      border: 1px dashed #e4e4e7;
      border-radius: 8px;
      color: #a1a1aa;
      font-family: 'Inter', sans-serif;
      font-size: 13px;
    }

    .batch-section-empty.show {
      display: flex;
    }

    .batch-cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      gap: 12px;
    }

    .batch-card {
      display: flex;
      flex-direction: column;
      padding: 16px;
      background: #ffffff;
      border: 1px solid #e4e4e7;
      border-radius: 8px;
      cursor: pointer;
      transition: background 0.12s, border-color 0.12s;
    }

    .batch-card:hover {
      background: #fafafa;
      border-color: #d4d4d8;
    }

    .batch-card-id {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 4px;
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      font-family: 'Inter', sans-serif;
      font-size: 11px;
      font-weight: 600;
      color: #0284c7;
      margin-bottom: 8px;
      width: fit-content;
    }

    .batch-card-count {
      font-family: 'Inter', sans-serif;
      font-size: 16px;
      font-weight: 600;
      color: #18181b;
      margin-bottom: 8px;
    }

    .batch-card-tags {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
      margin-bottom: 10px;
    }

    .batch-card-tag {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 4px;
      background: #f4f4f5;
      font-family: 'Inter', sans-serif;
      font-size: 11px;
      font-weight: 400;
      color: #52525b;
    }

    .batch-card-progress-bar {
      width: 100%;
      height: 4px;
      background: #e4e4e7;
      border-radius: 2px;
      overflow: hidden;
      margin-bottom: 6px;
    }

    .batch-card-progress-fill {
      height: 100%;
      background: #045e1f;
      border-radius: 2px;
      transition: width 0.3s ease;
    }

    .batch-card-progress-fill.low {
      background: #045e1f;
    }

    .batch-card-progress-fill.mid {
      background: #045e1f;
    }

    .batch-card-progress-fill.full {
      background: #045e1f;
    }

    .batch-card-progress-text {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-family: 'Inter', sans-serif;
      font-size: 12px;
      color: #71717a;
    }

    .batch-card-progress-text strong {
      color: #18181b;
      font-weight: 500;
    }

    .batch-card-progress-pct {
      font-weight: 600;
      color: #18181b;
    }

    /* Rescue Batch Dossier Modal */
    .dossier-summary-card {
      background: #f9fafb;
      border: 1px solid #e4e4e7;
      border-radius: 8px;
      padding: 16px;
      margin-bottom: 20px;
    }

    .dossier-batch-id {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 4px;
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      font-family: 'Inter', sans-serif;
      font-size: 11px;
      font-weight: 600;
      color: #0284c7;
      margin-bottom: 8px;
    }

    .dossier-count {
      font-family: 'Inter', sans-serif;
      font-size: 18px;
      font-weight: 600;
      color: #18181b;
      margin-bottom: 10px;
    }

    .dossier-tags {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
      margin-bottom: 12px;
    }

    .dossier-tag {
      display: inline-block;
      padding: 3px 10px;
      border-radius: 4px;
      background: #f4f4f5;
      font-family: 'Inter', sans-serif;
      font-size: 12px;
      color: #52525b;
    }

    .dossier-progress-bar {
      width: 100%;
      height: 4px;
      background: #e4e4e7;
      border-radius: 2px;
      overflow: hidden;
      margin-bottom: 6px;
    }

    .dossier-progress-fill {
      height: 100%;
      background: #045e1f;
      border-radius: 2px;
      transition: width 0.3s ease;
    }

    .dossier-progress-text {
      display: flex;
      justify-content: space-between;
      font-family: 'Inter', sans-serif;
      font-size: 12px;
      color: #71717a;
    }

    .dossier-progress-text strong {
      color: #18181b;
      font-weight: 500;
    }

    .dossier-progress-pct {
      font-weight: 600;
      color: #18181b;
    }

    .dossier-section-label {
      font-family: 'Inter', sans-serif;
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      color: #71717a;
      margin-bottom: 10px;
    }

    .animal-slot {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 14px;
      background: #ffffff;
      border: 1px solid #e4e4e7;
      border-radius: 8px;
      margin-bottom: 8px;
      transition: background 0.12s;
    }

    .animal-slot:hover {
      background: #fafafa;
    }

    .animal-slot.completed {
      border-color: #bbf7d0;
      background: #f0fdf4;
    }

    .animal-slot-num {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 28px;
      height: 28px;
      border-radius: 6px;
      background: transparent;
      font-family: 'Inter', sans-serif;
      font-size: 12px;
      font-weight: 600;
      color: #18181b;
      flex-shrink: 0;
    }

    .animal-slot.completed .animal-slot-num {
      background: transparent;
      color: #18181b;
    }

    .animal-slot-info {
      flex: 1;
      min-width: 0;
    }

    .animal-slot-name {
      font-family: 'Inter', sans-serif;
      font-size: 14px;
      font-weight: 500;
      color: #18181b;
    }

    .animal-slot-status {
      font-family: 'Inter', sans-serif;
      font-size: 12px;
      color: #f59e0b;
    }

    .animal-slot.completed .animal-slot-status {
      color: #16a34a;
    }

    .animal-slot-action {
      flex-shrink: 0;
    }

    .btn-fill-profile {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 5px 12px;
      border-radius: 6px;
      border: 1px solid #f59e0b;
      background: #fffbeb;
      color: #d97706;
      font-family: 'Inter', sans-serif;
      font-size: 12px;
      font-weight: 500;
      cursor: pointer;
      transition: background 0.12s;
      white-space: nowrap;
    }

    .btn-fill-profile:hover {
      background: #fef3c7;
    }

    .btn-fill-profile.done {
      border-color: #bbf7d0;
      background: #f0fdf4;
      color: #16a34a;
      cursor: default;
    }

    /* Modal empty state placeholder */
    .modal-empty-state {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 32px 16px;
      background: #f9fafb;
      border: 1px solid #e4e4e7;
      border-radius: 6px;
      color: #a1a1aa;
      font-family: 'Inter', sans-serif;
      font-size: 13px;
      text-align: center;
      min-height: 120px;
    }

    /* Pet Information Card */
    .pet-info-card-enhanced {
      background: #f9fafb;
      border: 1px solid #e4e4e7;
      border-radius: 8px;
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 14px;
    }

    .pet-info-header-row {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .pet-info-photo-lg {
      width: 58px;
      height: 58px;
      border-radius: 50%;
      object-fit: cover;
      background: #e4e4e7;
      flex-shrink: 0;
    }

    .pet-info-primary-title {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 15px;
      font-weight: 600;
      color: #18181b;
      line-height: 1.2;
    }

    .pet-info-primary-sub {
      font-size: 14px;
      color: #71717a;
      font-weight: 400;
      margin-top: 2px;
    }

    .pet-info-details-table {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px 16px;
      font-size: 14px;
    }

    .pet-detail-k {
      color: #71717a;
      font-weight: 500;
    }

    .pet-detail-v {
      color: #18181b;
      font-weight: 600;
    }

    /* Adoption Readiness Section */
    .readiness-container {
      background: #f9fafb;
      border: 1px solid #e4e4e7;
      border-radius: 8px;
      padding: 14px 16px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .readiness-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px 16px;
    }

    .readiness-item {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 14px;
    }

    .readiness-name {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-weight: 500;
      color: #18181b;
    }

    .readiness-check {
      color: #18181b;
      font-weight: 700;
    }

    .readiness-val {
      font-weight: 500;
      color: #18181b;
      font-size: 13px;
    }

    .btn-view-pet-record {
      align-self: flex-end;
      font-size: 12px;
      font-weight: 600;
      color: #045e1f;
      background: transparent;
      border: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 0;
      transition: all 0.15s ease;
      text-decoration: none;
    }

    .btn-view-pet-record:hover {
      text-decoration: underline;
    }

    /* Status Helper Callout */
    .status-helper-tooltip {
      display: inline-flex;
      align-items: center;
      position: relative;
      vertical-align: middle;
      margin-left: 4px;
    }

    .status-helper-icon {
      width: 16px;
      height: 16px;
      border-radius: 50%;
      background: #e4e4e7;
      color: #52525b;
      font-size: 11px;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: default;
      flex-shrink: 0;
    }

    .status-helper-tip {
      display: none;
      position: absolute;
      bottom: calc(100% + 26px);
      left: 0;
      background: #18181b;
      color: #ffffff;
      border-radius: 6px;
      padding: 8px 12px;
      font-size: 12px;
      font-weight: 500;
      line-height: 1.4;
      width: 280px;
      white-space: normal;
      z-index: 20;
    }

    .status-helper-tip::after {
      content: '';
      position: absolute;
      top: 100%;
      left: 6px;
      border: 5px solid transparent;
      border-top-color: #18181b;
    }

    .status-helper-tooltip:hover .status-helper-tip {
      display: block;
    }

    .form-divider {
      height: 1px;
      background: #e4e4e7;
      margin: 6px 0;
    }

    .modal-footer {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 8px;
      padding-top: 4px;
    }

    .btn-cancel {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      height: 36px;
      padding: 0 16px;
      background: #fff;
      border: 1px solid #e4e4e7;
      border-radius: 6px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 500;
      color: #18181b;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .btn-cancel:hover {
      background: #f4f4f5;
    }

    .btn-submit {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      height: 36px;
      padding: 0 16px;
      background: #18181b;
      border: none;
      border-radius: 6px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 500;
      color: #ffffff;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .btn-submit:hover {
      background: #333;
    }

    /* ==========================================================================
       PET DETAILS & LIFECYCLE ACTION DRAWER
       ========================================================================== */
    .drawer-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.4);
      backdrop-filter: blur(3px);
      display: none;
      z-index: 900;
      opacity: 0;
      transition: opacity 0.2s ease;
    }

    .drawer-backdrop.show {
      display: block;
      opacity: 1;
    }

    .pet-drawer {
      position: fixed;
      top: 0;
      right: 0;
      width: 480px;
      max-width: 90vw;
      height: 100vh;
      background: #FFFFFF;
      box-shadow: -8px 0 40px rgba(15,23,32,.18);
      z-index: 950;
      display: flex;
      flex-direction: column;
      transform: translateX(100%);
      transition: transform 0.32s cubic-bezier(.32,.72,.25,1);
    }

    .pet-drawer.show {
      transform: translateX(0);
    }

    .drawer-header {
      padding: 20px 24px 8px;
      border-bottom: none;
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
    }

    .drawer-title {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 18px;
      font-weight: 600;
      color: #18181b;
      letter-spacing: -0.025em;
      line-height: 1;
      margin: 0;
    }

    .drawer-subtitle {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 400;
      color: #71717a;
      margin: 4px 0 0;
    }

    .drawer-body {
      padding: 20px 24px;
      overflow-y: auto;
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 0;
    }

    .drawer-hero-profile {
      display: flex;
      gap: 12px;
      align-items: center;
      padding-bottom: 20px;
    }

    .drawer-pet-img {
      width: 85px;
      height: 85px;
      border-radius: 8px;
      object-fit: cover;
      background: #e4e4e7;
      border: none;
      flex-shrink: 0;
    }

    .drawer-pet-details {
      display: flex;
      flex-direction: column;
      gap: 2px;
      min-width: 0;
      flex: 1;
    }

    .drawer-pet-role {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 12px;
      font-weight: 400;
      color: #71717a;
    }

    .drawer-pet-name {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 18px;
      font-weight: 600;
      color: #18181b;
      line-height: 1;
      letter-spacing: -0.025em;
      margin: 0;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .drawer-pills-row {
      display: flex;
      gap: 8px;
      margin-top: 6px;
      flex-wrap: wrap;
    }

    .drawer-status-badge {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 12px;
      font-weight: 500;
      padding: 3px 10px;
      border-radius: 4px;
      border: 1px solid #e4e4e7;
      white-space: nowrap;
      line-height: 1.4;
    }

    .drawer-status-badge.available { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
    .drawer-status-badge.reserved { background: #fef3c7; color: #92400e; border-color: #fde68a; }
    .drawer-status-badge.pending { background: #fefce8; color: #a16207; border-color: #fef08a; }
    .drawer-status-badge.adopted { background: #dbeafe; color: #1e40af; border-color: #bfdbfe; }

    .drawer-section-title {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 600;
      color: #18181b;
      margin: 18px 0 10px;
    }

    .info-card-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 12px;
    }

    .info-card-head h3 {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 600;
      color: #18181b;
      margin: 0;
    }
    .info-card-head .pool-edit-icon { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; flex:0 0 30px; padding:0; border:1px solid #e4e4e7; border-radius:6px; background:#fff; cursor:pointer; }
    .info-card-head .pool-edit-icon img { display:block; width:16px; height:16px; object-fit:contain; }
    .info-card-head .pool-edit-icon:hover { border-color:#18181b; background:#f4f4f5; }

    .drawer-info-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px 14px;
      margin-bottom: 20px;
    }

    .drawer-info-item {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .drawer-info-label {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 13px;
      font-weight: 600;
      color: #71717a;
    }

    .drawer-info-val {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 15px;
      font-weight: 400;
      color: #18181b;
    }

    .readiness-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 22px;
    }

    .readiness-table th {
      text-align: left;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 13px;
      font-weight: 600;
      color: #71717a;
      padding: 6px 8px;
      border-bottom: 1px solid #e4e4e7;
      background: transparent;
      text-transform: none;
    }

    .readiness-table td {
      padding: 9px 8px;
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 15px;
      font-weight: 400;
      color: #18181b;
      border-bottom: 1px solid #e4e4e7;
    }

    .drawer-desc-box {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 14px;
      font-weight: 400;
      color: #71717a;
      background: #f9fafb;
      padding: 12px;
      border: 1px solid #e4e4e7;
      border-radius: 8px;
      line-height: 1.5;
    }

    .adoption-progress {
      display: flex;
      flex-direction: row;
      align-items: flex-start;
      padding: 8px 0 16px;
      position: relative;
    }

    .adoption-progress::before {
      content: '';
      position: absolute;
      top: 17px;
      left: 27px;
      right: 27px;
      height: 2px;
      background: #d4d4d8;
    }

    .progress-step {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 6px;
      position: relative;
      z-index: 1;
      flex: 1;
    }

    .progress-dot {
      width: 18px;
      height: 18px;
      border-radius: 50%;
      border: 2px solid #d4d4d8;
      background: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      transition: all 0.2s;
      position: relative;
    }

    .progress-dot.done {
      border-color: #16a34a;
      background: #16a34a;
      color: #ffffff;
    }

    .progress-dot.active {
      border-color: #16a34a;
      background: #ffffff;
    }

    .progress-line {
      display: none;
    }

    .progress-label {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      font-size: 11px;
      font-weight: 500;
      color: #a1a1aa;
      text-align: center;
      white-space: normal;
    }

    .progress-label.active-label {
      color: #18181b;
      font-weight: 600;
    }

    .drawer-footer {
      padding: 12px 24px;
      border-top: 1px solid #e4e4e7;
      background: #ffffff;
      display: flex;
      align-items: center;
      gap: 8px;
      justify-content: center;
    }

    .btn-toggle-publish {
      flex: 1;
      height: 36px;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: var(--transition);
      border: 1px solid transparent;
      box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
    }

    .btn-toggle-publish.publish-action {
      background: #18181b;
      color: #fafafa;
      border-color: #18181b;
    }

    .btn-toggle-publish.publish-action:hover {
      background: #27272a;
    }

    .btn-toggle-publish.unpublish-action {
      background: #ffffff;
      color: #18181b;
      border-color: #e4e4e7;
    }

    .btn-toggle-publish.unpublish-action:hover {
      background: #f4f4f5;
    }

    .btn-toggle-publish.available-action {
      background: #2563EB;
      color: #ffffff;
      border-color: #2563EB;
    }

    .btn-toggle-publish.available-action:hover {
      background: #1d4ed8;
    }

    .btn-toggle-publish.adopt-action {
      background: #16a34a;
      color: #ffffff;
      border-color: #16a34a;
    }

    .btn-toggle-publish.adopt-action:hover {
      background: #15803d;
    }

    .btn-mark-adopted {
      height: 36px;
      padding: 0 16px;
      background: #18181b;
      color: #fafafa;
      border: 1px solid #18181b;
      border-radius: 6px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      transition: var(--transition);
      box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
    }

    .btn-mark-adopted:hover {
      background: #27272a;
    }

    /* Toast Container — availability page style */
    .toast-container {
      position: fixed;
      bottom: 1.5rem;
      right: 1.5rem;
      display: flex;
      flex-direction: column-reverse;
      gap: 0.5rem;
      z-index: 2000;
      pointer-events: none;
    }

    .toast-pill {
      pointer-events: auto;
      display: flex;
      align-items: flex-start;
      gap: 0.75rem;
      min-width: 300px;
      max-width: 420px;
      padding: 1rem 1.25rem;
      border-radius: 0.625rem;
      font-size: 0.9375rem;
      line-height: 1.4;
      font-weight: 500;
      background: #fff;
      color: #18181b;
      border: 1px solid #e4e4e7;
      box-shadow: 0 8px 24px rgba(0,0,0,0.12), 0 2px 6px rgba(0,0,0,0.06);
      transform: translateX(calc(100% + 1.5rem));
      opacity: 0;
      transition: transform 0.3s cubic-bezier(0.16,1,0.3,1), opacity 0.25s ease;
    }

    .toast-pill.show {
      transform: translateX(0);
      opacity: 1;
    }

    .toast-pill.hiding {
      transform: translateX(calc(100% + 1.5rem));
      opacity: 0;
    }

    .toast-pill .toast-icon {
      flex-shrink: 0;
      width: 1.125rem;
      height: 1.125rem;
      margin-top: 1px;
    }

    .toast-pill .toast-body {
      flex: 1;
      min-width: 0;
    }

    .toast-pill .toast-title {
      font-weight: 600;
      color: #18181b;
      margin: 0;
    }

    .toast-pill .toast-desc {
      font-weight: 400;
      color: #71717a;
      margin: 0.125rem 0 0;
      font-size: 0.9375rem;
    }

    .toast-pill .toast-close {
      flex-shrink: 0;
      width: 1.25rem;
      height: 1.25rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: none;
      background: transparent;
      color: #71717a;
      cursor: pointer;
      border-radius: 0.25rem;
      transition: background 0.12s, color 0.12s;
    }

    .toast-pill .toast-close:hover {
      background: #f4f4f5;
      color: #18181b;
    }

    .toast-pill.success {
      border-color: #bbf7d0;
      background: #f0fdf4;
    }

    .toast-pill.success .toast-icon {
      color: #16a34a;
    }

    .toast-pill.success .toast-title {
      color: #065f46;
    }

    .toast-pill.error {
      border-color: #fecaca;
      background: #fef2f2;
    }

    .toast-pill.error .toast-icon {
      color: #dc2626;
    }

    .toast-pill.error .toast-title {
      color: #991b1b;
    }

    .toast-pill.info {
      border-color: #bae6fd;
      background: #f0f9ff;
    }

    .toast-pill.info .toast-icon {
      color: #0284c7;
    }

    .toast-pill.info .toast-title {
      color: #0c4a6e;
    }

    @media (max-width: 1080px) {
      .kpis {
        grid-template-columns: repeat(2, 1fr);
      }

      .table-responsive {
        overflow-x: auto;
        overflow-y: hidden;
        -webkit-overflow-scrolling: touch;
      }

      .data-table {
        min-width: 820px;
      }
    }

    @media (max-width: 768px) {
      .pool-body {
        width: 100%;
        margin: 0;
        padding: 12px;
        gap: 16px;
      }

      .page-action-toolbar {
        width: 100%;
        justify-content: stretch;
        flex-wrap: wrap;
      }

      .page-action-toolbar > .btn-primary-action,
      .page-action-toolbar > .rescue-dropdown-wrap {
        flex: 1 1 calc(50% - 4px);
        min-width: 0;
      }

      .page-action-toolbar .btn-primary-action,
      .page-action-toolbar .rescue-dropdown-wrap > button {
        width: 100%;
      }

      .rescue-dropdown-menu {
        width: min(280px, calc(100vw - 16px));
        min-width: 0;
      }

      .workflow-strip {
        align-items: flex-start;
        flex-direction: column;
        gap: 8px;
      }

      .workflow-flow {
        width: 100%;
        row-gap: 6px;
      }

      .kpis {
        grid-template-columns: 1fr;
        gap: 10px;
      }

      .kpi-card {
        min-height: 96px;
        padding: 14px 12px;
      }

      .pool-tabs {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
      }

      .pool-tab {
        flex: 0 0 auto;
        min-height: 42px;
        padding: 10px 14px;
        white-space: nowrap;
      }

      .table-filter-bar {
        align-items: stretch;
        flex-direction: column;
        gap: 8px;
      }

      .table-filter-actions {
        width: 100%;
        margin-left: 0;
      }

      .table-filter-actions > .btn-primary-action,
      .table-filter-actions > .rescue-dropdown-wrap {
        flex: 1 1 calc(50% - 4px);
        min-width: 0;
      }

      .table-filter-actions .btn-primary-action,
      .table-filter-actions .rescue-dropdown-wrap > button {
        width: 100%;
      }

      .filter-left-controls {
        width: 100%;
        align-items: stretch;
        flex-direction: column;
        gap: 8px;
      }

      .search-grow-wrap {
        width: 100%;
        min-width: 0;
      }

      .table-filter-select,
      .btn-reset-filters {
        width: 100%;
      }

      .filter-count-badge {
        align-self: flex-start;
      }

      .table-responsive {
        border-radius: 8px;
      }

      .dt-pagination {
        align-items: stretch;
        flex-direction: column;
        gap: 10px;
        padding: 10px 12px;
      }

      .dt-pagination-left,
      .dt-pagination-right,
      .dt-page-btns {
        justify-content: space-between;
        width: 100%;
      }

      .dt-pagination-right {
        gap: 8px;
      }

      .dt-page-label {
        width: auto;
      }

      .modal-backdrop {
        align-items: flex-start;
        overflow-y: auto;
        padding: 8px;
      }

      .modal-window {
        width: 100%;
        max-width: none;
        max-height: calc(100dvh - 16px);
        margin: 0;
        padding: 18px 16px;
        border-radius: 10px;
      }

      .modal-window form,
      .modal-window .modal-form-body,
      .modal-window .form-group {
        min-width: 0;
      }

      .modal-window .form-grid-2,
      .modal-window div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
      }

      .modal-window input,
      .modal-window select,
      .modal-window textarea {
        width: 100%;
        max-width: 100%;
        min-width: 0;
      }

      .rescue-identity-photo {
        flex: 1 1 140px;
        width: auto;
        max-width: 180px;
      }

      .modal-footer {
        align-items: stretch;
        flex-direction: column-reverse;
      }

      .modal-footer .btn-cancel,
      .modal-footer .btn-submit,
      .modal-footer .sd-btn {
        width: 100%;
        justify-content: center;
      }

      .pet-drawer {
        width: 100%;
        max-width: 100%;
      }

      .drawer-header,
      .drawer-body {
        padding-left: 16px;
        padding-right: 16px;
      }

      .drawer-footer {
        align-items: stretch;
        flex-wrap: wrap;
        padding: 12px 16px;
      }

      .drawer-footer .sd-btn {
        flex: 1 1 100%;
        min-width: 0;
      }

      .drawer-hero-profile {
        align-items: flex-start;
      }

      .drawer-pet-img {
        width: 72px;
        height: 72px;
      }

      .drawer-pet-name {
        white-space: normal;
        overflow-wrap: anywhere;
      }

      .drawer-info-grid {
        grid-template-columns: 1fr;
        gap: 8px;
      }

      .readiness-table {
        min-width: 0;
      }
    }

    .rescue-photo-upload {
      width: 100%;
      border: 1px solid hsl(240, 5.9%, 90%);
      border-radius: 0.5rem;
      cursor: pointer;
      overflow: hidden;
      position: relative;
      background: hsl(240, 4.8%, 95.9%);
    }

    .rescue-photo-placeholder {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 24px 16px;
      color: hsl(240, 3.8%, 46.1%);
      font-family: 'Inter', sans-serif;
      font-size: 13px;
      line-height: 1.4;
    }

    .rescue-photo-preview {
      position: relative;
      width: 100%;
    }
    .rescue-photo-preview img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      display: block;
      border-radius: 0.5rem;
    }
    .rescue-photo-remove {
      position: absolute;
      top: 8px;
      right: 8px;
      width: 28px;
      height: 28px;
      border-radius: 0.5rem;
      background: hsl(0, 0%, 100%);
      color: hsl(240, 5.9%, 10%);
      border: 1px solid hsl(240, 5.9%, 90%);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      line-height: 1;
      box-shadow: 0 1px 2px rgba(0,0,0,0.05);
      transition: background 0.15s;
    }
    .rescue-photo-remove:hover {
      background: hsl(240, 4.8%, 95.9%);
    }

    .rescue-identity-photo {
      flex: 0 0 180px;
      width: 180px;
    }
    .rescue-identity-photo .form-label {
      margin-bottom: 6px;
    }
    .rescue-identity-photo .rescue-photo-upload { min-height: 180px; }
    .rescue-identity-photo .rescue-photo-placeholder { padding: 16px 8px; gap: 6px; text-align: center; justify-content: center; align-items: center; }
    .rescue-identity-photo .rescue-photo-placeholder svg { width: 20px; height: 20px; }
    .rescue-identity-photo .rescue-photo-placeholder span { font-size: 11px; text-align: center; }
    .rescue-identity-photo .rescue-photo-preview img { max-height: 180px; }

    .batch-slot-photo-upload {
      width: 100%;
      border: 1px solid hsl(240, 5.9%, 90%);
      border-radius: 0.5rem;
      cursor: pointer;
      overflow: hidden;
      position: relative;
      background: hsl(240, 4.8%, 95.9%);
    }
    .batch-slot-photo-upload .rescue-photo-placeholder { padding: 16px 12px; }
    .batch-slot-photo-upload .rescue-photo-preview img { max-height: 120px; }
  </style>
</head>
<body>
<?php include 'includes/staff-sidebar.php'; ?>
<main class="main-content">
<div class="main-wrapper">
    <!-- Operational Body: Adoption Pool Contents Only -->
    <main class="pool-body">

      <!-- ==========================================================================
           1. TOP SUMMARY METRIC CARDS (CLICKABLE QUICK-FILTERS)
           ========================================================================== -->
      <div class="kpis">
        <div class="kpi-card" data-filter-status="Pending Review" id="card-filter-pending">
          <p class="kpi-label">Pending Review</p>
          <div class="kpi-value" id="count-pending">0</div>
          <p class="kpi-sub">Awaiting review before availability</p>
        </div>
        <div class="kpi-card" data-filter-status="Available" data-filter-listing="Not Listed" id="card-filter-available">
          <p class="kpi-label">Available</p>
          <div class="kpi-value" id="count-available">0</div>
          <p class="kpi-sub">Ready for adoption, not yet published</p>
        </div>
        <div class="kpi-card" data-filter-listing="Published" id="card-filter-published">
          <p class="kpi-label">Published</p>
          <div class="kpi-value" id="count-published">0</div>
          <p class="kpi-sub">Live on Resident Adoption Portal</p>
        </div>
        <div class="kpi-card" data-filter-status="Adopted" id="card-filter-adopted">
          <p class="kpi-label">Adopted</p>
          <div class="kpi-value" id="count-adopted">0</div>
          <p class="kpi-sub">Successfully adopted</p>
        </div>
      </div>

      <!-- Tab Navigation -->
      <div class="pool-tabs">
        <button class="pool-tab active" data-tab="tab-pool">Adoption Pool</button>
        <button class="pool-tab" data-tab="tab-applications">Applications</button>
        <button class="pool-tab" data-tab="tab-batches">Rescue Batches</button>
      </div>

      <!-- TAB: Adoption Pool -->
      <div class="tab-panel active" id="tab-pool">
        <!-- Table Filter Bar -->
        <div class="table-filter-bar">
        <div class="filter-left-controls">
          <!-- Search input -->
          <div class="search-grow-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" id="table-search-input" class="table-search-input" placeholder="Search pet name, breed, ID...">
          </div>

          <!-- Species Dropdown Filter -->
          <select id="filter-species" class="table-filter-select">
            <option value="all">Species: All ▾</option>
            <option value="Dog">Dog</option>
            <option value="Cat">Cat</option>
          </select>

          <!-- Rescue Source Dropdown Filter -->
          <select id="filter-source" class="table-filter-select">
            <option value="all">Rescue Source: All ▾</option>
            <option value="Barangay-Reported">Barangay-Reported</option>
            <option value="Resident Surrender">Resident Surrender</option>
            <option value="Stray Roundup">Stray Roundup</option>
          </select>

          <!-- Adoption Status Dropdown Filter -->
          <select id="filter-adoption-status" class="table-filter-select">
            <option value="all">Adoption Status: All ▾</option>
            <option value="Pending Review">Pending Review</option>
            <option value="Available">Available</option>
            <option value="On Hold">On Hold</option>
            <option value="Adopted">Adopted</option>
          </select>

          <!-- Listing Status Dropdown Filter -->
          <select id="filter-listing-status" class="table-filter-select">
            <option value="all">Listing: All ▾</option>
            <option value="Not Listed">Not Listed</option>
            <option value="Published">Published</option>
            <option value="Archived">Archived</option>
          </select>

          <!-- Reset Button -->
          <button class="btn-reset-filters" id="btn-reset-filters">Clear Filters</button>
        </div>
        <div class="table-filter-actions">
          <button class="btn-secondary-action" type="button" onclick="exportAdoptionPoolCSV()" title="Export filtered adoption pool as CSV">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            <span>Export CSV</span>
          </button>
          <!-- Record Rescue Dropdown -->
          <div class="rescue-dropdown-wrap" id="rescue-dropdown-wrap">
            <button class="btn-secondary-action" id="header-record-rescue-btn">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 0 0 7.75"/>
              </svg>
              <span>Record Rescues</span>
            </button>
            <div class="rescue-dropdown-menu" id="rescue-dropdown-menu">
              <button class="rescue-dropdown-item" data-type="single">
                <span class="rescue-dropdown-icon">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </span>
                <span class="rescue-dropdown-text">
                  <span class="rescue-dropdown-label">Single Animal</span>
                  <span class="rescue-dropdown-desc">Record one rescued animal</span>
                </span>
              </button>
              <button class="rescue-dropdown-item" data-type="multiple">
                <span class="rescue-dropdown-icon">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                <span class="rescue-dropdown-text">
                  <span class="rescue-dropdown-label">Multiple Animals</span>
                  <span class="rescue-dropdown-desc">Rescue batch — record many at once</span>
                </span>
              </button>
            </div>
          </div>

          <!-- Primary Action: + Add to Adoption Pool -->
          <button class="btn-primary-action" id="open-add-pool-btn">
            <span>+ Add to Adoption Pool</span>
          </button>
        </div>
      </div>

      <section class="table-panel">
        <!-- Bulk Actions Bar (checkbox selection quick actions) -->
        <div class="bulk-bar" id="poolBulkBar">
          <div class="bulk-actions">
            <span class="bulk-count"><span id="poolBulkCount">0</span> animals selected</span>
            <button class="bulk-btn" onclick="bulkPublish()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>Publish</button>
            <button class="bulk-btn" onclick="bulkUnpublish()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>Unpublish</button>
            <button class="bulk-btn" onclick="bulkSetAvailable()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>Available</button>
            <button class="bulk-btn danger" onclick="bulkArchive()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>Archive</button>
          </div>
        </div>
        <!-- Table View -->
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width:40px;"><input type="checkbox" class="sd-table-cb" id="poolSelectAll" onclick="togglePoolSelectAll(this)" /></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="name" onclick="sortBy('name')">Pet<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="gender" onclick="sortBy('gender')">Gender<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="breed" onclick="sortBy('breed')">Breed<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="age" onclick="sortBy('age')">Age<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="species" onclick="sortBy('species')">Species<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="rescueSource" onclick="sortBy('rescueSource')">Rescue Source<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="rescueDate" onclick="sortBy('rescueDate')">Rescue Date<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="adoptionStatus" onclick="sortBy('adoptionStatus')">Adoption Status<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="adoptedDate" onclick="sortBy('adoptedDate')">Confirmed/Adopted Date<svg class="th-sort-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th">Pickup Period</th>
                <th class="sd-th"><button type="button" class="th-sort" data-col="listingStatus" onclick="sortBy('listingStatus')">Listing<svg class="th-sort-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/></svg></button></th>
                <th class="sd-th sd-th-action"></th>
              </tr>
            </thead>
            <tbody id="pool-table-body">
              <!-- Rendered dynamically by Javascript -->
            </tbody>
          </table>

          <!-- Empty State -->
          <div class="table-empty-state" id="table-empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
              <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <p style="font-weight: 700; color: var(--text-main);">No matching animals found</p>
            <p style="font-size: 12px;">Try adjusting your search terms or filter selections.</p>
          </div>
        </div>
        <div class="dt-pagination">
          <div class="dt-pagination-right">
            <div class="dt-rows">
              <span>Rows per page</span>
              <select id="poolPerPage" onchange="setPoolPerPage(this.value)">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
              </select>
            </div>
            <div class="dt-page-label" id="poolPageLabel">Page 1 of 1</div>
            <div class="dt-page-btns">
              <button class="dt-page-btn" id="poolPageFirst" title="Go to first page"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg></button>
              <button class="dt-page-btn" id="poolPagePrev" title="Go to previous page"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
              <button class="dt-page-btn" id="poolPageNext" title="Go to next page"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>
              <button class="dt-page-btn" id="poolPageLast" title="Go to last page"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg></button>
            </div>
          </div>
        </div>
      </section>
      <div class="cpd row-menu-pop" id="poolRowMenuPop"></div>

      </div><!-- /tab-pool -->

      <!-- TAB: Adoption Applications -->
      <div class="tab-panel" id="tab-applications">
        <div class="adoption-review-notice" id="adoption-review-notice" role="status" hidden></div>
        <div id="adoption-application-queue"></div>
        <script type="application/json" id="adoption-review-data"><?php
          echo json_encode(
              ['applications' => $adoptionApplications],
              JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
          );
        ?></script>
      </div><!-- /tab-applications -->

      <div class="adoption-document-dialog" id="adoption-document-dialog" aria-hidden="true">
        <section class="adoption-document-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="adoption-document-dialog-title">
          <div class="adoption-document-dialog-header">
            <h2 class="adoption-document-dialog-title" id="adoption-document-dialog-title">Proof of residency</h2>
            <button type="button" class="adoption-document-dialog-close" id="adoption-document-dialog-close">Close</button>
          </div>
          <iframe class="adoption-document-preview" id="adoption-document-preview" title="Residency proof preview"></iframe>
        </section>
      </div>
      <div class="adoption-action-dialog" id="adoption-action-dialog" aria-hidden="true">
        <section class="adoption-action-dialog-panel" role="alertdialog" aria-modal="true" aria-labelledby="adoption-action-dialog-title" aria-describedby="adoption-action-dialog-description">
          <h2 class="adoption-action-dialog-title" id="adoption-action-dialog-title"></h2>
          <p class="adoption-action-dialog-description" id="adoption-action-dialog-description"></p>
          <div class="adoption-action-schedule-field" id="adoption-action-schedule-field" hidden>
            <label for="adoption-action-pickup-at">DCVS visit date and time</label>
            <input type="datetime-local" id="adoption-action-pickup-at">
          </div>
          <div class="adoption-action-dialog-footer">
            <button type="button" class="adoption-action-dialog-cancel" id="adoption-action-dialog-cancel">Cancel</button>
            <button type="button" class="adoption-action-dialog-confirm" id="adoption-action-dialog-confirm"></button>
          </div>
        </section>
      </div>

      <!-- TAB: Rescue Batches -->
      <div class="tab-panel" id="tab-batches">
        <div class="batch-section" id="batch-section">
          <div class="batch-cards-grid" id="batch-cards-grid">
            <!-- Rendered by JS -->
          </div>
          <p id="batch-section-empty" style="display:none; font-family: 'Inter', sans-serif; font-size: 13px; color: #71717a; text-align: center; padding: 24px 0;">No rescue batches recorded yet. Use "Record Rescue → Multiple Animals" to create one.</p>
        </div>
      </div><!-- /tab-batches -->

      </div><!-- /tab panels wrapper -->

    </main>

  </div>

  <!-- ==========================================================================
       MODAL: ADD TO ADOPTION POOL (RECOMMENDED EXACT UI)
       ========================================================================== -->
  <div class="modal-backdrop" id="add-pool-modal">
    <div class="modal-window">
      <div class="modal-head">
        <h3 class="modal-title">Add to Adoption Pool</h3>
        <p class="modal-subtitle">Add an existing rescued animal to the adoption workflow.</p>
      </div>
      <button class="btn-modal-close" id="close-add-pool-modal" title="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>

      <form id="add-pool-form">
        <div class="modal-form-body">
          
          <!-- Section 1: SELECT PET -->
          <div>
            <div class="modal-section-label">SELECT PET</div>
            <div class="form-group">
              <select id="select-existing-pet" class="form-select" required>
                <!-- Populated dynamically -->
              </select>
              <span style="font-size: 13px; color: #71717a; margin-top: 2px;">Choose an existing registered or rescued animal.</span>
            </div>
          </div>

          <!-- Section 2: PET INFORMATION -->
          <div>
            <div class="modal-section-label">PET INFORMATION</div>
            <div id="modal-preview-empty" class="modal-empty-state">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              <span>Select a pet from the dropdown above to preview</span>
            </div>
            <div id="modal-preview-content" class="pet-info-card-enhanced" style="display:none;">
              <div class="pet-info-header-row">
                <img src="aspin4.jpeg" alt="Pet preview" class="pet-info-photo-lg" id="modal-preview-photo">
                <div>
                  <div class="pet-info-primary-title" id="modal-preview-name"></div>
                  <div class="pet-info-primary-sub" id="modal-preview-meta"></div>
                </div>
              </div>

              <div class="pet-info-details-table">
                <span class="pet-detail-k">Rescue Source</span>
                <span class="pet-detail-v" id="modal-preview-source"></span>

                <span class="pet-detail-k">Rescue Date</span>
                <span class="pet-detail-v" id="modal-preview-date"></span>

                <span class="pet-detail-k">Barangay</span>
                <span class="pet-detail-v" id="modal-preview-brgy"></span>

                <span class="pet-detail-k">Current Location</span>
                <span class="pet-detail-v" id="modal-preview-loc"></span>
              </div>
            </div>
          </div>

          <!-- Section 3: ADOPTION READINESS -->
          <div style="margin-top: 16px;">
            <div class="modal-section-label">ADOPTION READINESS</div>
            <span style="font-size: 12px; color: #71717a; margin-bottom: 10px; display: block;">Complete the readiness assessment before making this animal available for adoption.</span>
            <div id="readiness-empty" class="modal-empty-state">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="1.5"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
              <span>Select a pet to view adoption readiness</span>
            </div>
            <div id="readiness-content" style="display:none;">
              <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px;">
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label" for="readiness-vaccination">Vaccination Status</label>
                  <select id="readiness-vaccination" class="form-select">
                    <option value="Not specified" selected>Not specified</option>
                    <option value="Up to date">Up to date</option>
                    <option value="Partially vaccinated">Partially vaccinated</option>
                    <option value="Not vaccinated">Not vaccinated</option>
                  </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label" for="readiness-health">Health / Medical Status</label>
                  <select id="readiness-health" class="form-select">
                    <option value="Not assessed" selected>Not assessed</option>
                    <option value="Cleared">Cleared</option>
                    <option value="Under treatment">Under treatment</option>
                    <option value="Needs attention">Needs attention</option>
                  </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label" for="readiness-spay-neuter">Spay / Neuter Status</label>
                  <select id="readiness-spay-neuter" class="form-select">
                    <option value="Not specified" selected>Not specified</option>
                    <option value="Completed">Completed</option>
                    <option value="Scheduled">Scheduled</option>
                    <option value="Not applicable">Not applicable</option>
                  </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label" for="readiness-temperament">Temperament Assessment</label>
                  <select id="readiness-temperament" class="form-select">
                    <option value="Not assessed" selected>Not assessed</option>
                    <option value="Assessed">Assessed</option>
                    <option value="Needs evaluation">Needs evaluation</option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <!-- Section 4: ADOPTION AVAILABILITY -->
          <div style="margin-top: 16px;">
            <div class="modal-section-label">ADOPTION AVAILABILITY</div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; margin-bottom: 12px;">
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="pool-status-select">Status <span class="req">*</span>
                  <span class="status-helper-tooltip" id="status-helper-callout" style="display:none;">
                    <span class="status-helper-icon">!</span>
                    <span class="status-helper-tip" id="status-helper-text"></span>
                  </span>
                </label>
                <select id="pool-status-select" class="form-select" required>
                  <option value="Pending Review" selected>Pending Review</option>
                  <option value="Available">Available</option>
                  <option value="On Hold">On Hold</option>
                </select>
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="pool-ready-date">Availability Date</label>
                <input type="date" id="pool-ready-date" class="form-input" value="2026-09-19" required>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="pool-notes">Description or Behavior</label>
              <textarea id="pool-notes" class="form-textarea" placeholder="Optional adoption-related notes..."></textarea>
            </div>
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn-cancel" id="cancel-add-pool-modal">Cancel</button>
          <button type="submit" class="btn-submit">Add to Adoption Pool</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Edit Pet Details Modal -->
  <div class="modal-backdrop" id="edit-pet-details-modal">
    <div class="modal-window">
      <div class="modal-head">
        <h3 class="modal-title">Edit Pet Details</h3>
        <p class="modal-subtitle">Edit the rescued animal and adoption details.</p>
      </div>
      <button class="btn-modal-close" id="close-edit-pet-details-modal" title="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>

      <form id="edit-pet-details-form">
        <div class="modal-form-body">
          <div>
            <div class="modal-section-label">SELECT PET</div>
            <div class="form-group">
              <select id="edit-pet-select" class="form-select" disabled></select>
              <span style="font-size: 13px; color: #71717a; margin-top: 2px;">Editing the selected rescued animal.</span>
            </div>
          </div>

          <div>
            <div class="modal-section-label">PET INFORMATION</div>
            <div class="form-grid-2" style="margin-bottom: 12px;">
            <div class="form-group">
              <label class="form-label" for="edit-pet-name">Pet Name <span class="req">*</span></label>
              <input type="text" id="edit-pet-name" class="form-input" required>
            </div>
            <div class="form-group">
              <label class="form-label" for="edit-pet-species">Species <span class="req">*</span></label>
              <select id="edit-pet-species" class="form-select" required>
                <option value="Dog">Dog</option>
                <option value="Cat">Cat</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="edit-pet-breed">Breed <span class="req">*</span></label>
              <input type="text" id="edit-pet-breed" class="form-input" required>
            </div>
            <div class="form-group">
              <label class="form-label" for="edit-pet-gender">Gender <span class="req">*</span></label>
              <select id="edit-pet-gender" class="form-select" required>
                <option value="Female">Female</option>
                <option value="Male">Male</option>
                <option value="Unknown">Unknown</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label" for="edit-pet-age">Age <span class="req">*</span></label>
              <input type="text" id="edit-pet-age" class="form-input" placeholder="e.g. 2 years" required>
            </div>
            <div class="form-group">
              <label class="form-label" for="edit-pet-rescue-date">Rescue Date <span class="req">*</span></label>
              <input type="date" id="edit-pet-rescue-date" class="form-input" required>
            </div>
            </div>
          </div>

          <div>
            <div class="modal-section-label">ADOPTION READINESS</div>
            <span style="font-size: 12px; color: #71717a; margin-bottom: 10px; display: block;">Update the readiness assessment for this animal.</span>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px;">
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="edit-readiness-vaccination">Vaccination Status</label>
                <select id="edit-readiness-vaccination" class="form-select">
                  <option value="Not specified">Not specified</option><option value="Up to date">Up to date</option><option value="Partially vaccinated">Partially vaccinated</option><option value="Not vaccinated">Not vaccinated</option>
                </select>
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="edit-readiness-health">Health / Medical Status</label>
                <select id="edit-readiness-health" class="form-select">
                  <option value="Not assessed">Not assessed</option><option value="Cleared">Cleared</option><option value="Under treatment">Under treatment</option><option value="Needs attention">Needs attention</option>
                </select>
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="edit-readiness-spay-neuter">Spay / Neuter Status</label>
                <select id="edit-readiness-spay-neuter" class="form-select">
                  <option value="Not specified">Not specified</option><option value="Completed">Completed</option><option value="Scheduled">Scheduled</option><option value="Not applicable">Not applicable</option>
                </select>
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="edit-readiness-temperament">Temperament Assessment</label>
                <select id="edit-readiness-temperament" class="form-select">
                  <option value="Not assessed">Not assessed</option><option value="Assessed">Assessed</option><option value="Needs evaluation">Needs evaluation</option>
                </select>
              </div>
            </div>
          </div>

          <div>
            <div class="modal-section-label">ADOPTION AVAILABILITY</div>
            <div style="display: grid; grid-template-columns: 1fr; gap: 10px 16px; margin-bottom: 12px;">
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="edit-pool-status">Status <span class="req">*</span></label>
                <select id="edit-pool-status" class="form-select" required>
                  <option value="Pending Review">Pending Review</option>
                  <option value="Available">Available</option>
                  <option value="On Hold">On Hold</option>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label" for="edit-pet-description">Description or Behavior</label>
              <textarea id="edit-pet-description" class="form-textarea" placeholder="Optional adoption-related notes..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-cancel" id="cancel-edit-pet-details-modal">Cancel</button>
          <button type="submit" class="btn-submit">Save Pet Details</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ==========================================================================
       PET DETAILS & LIFECYCLE ACTION DRAWER
       ========================================================================== -->
  <div class="drawer-backdrop" id="drawer-backdrop"></div>

  <div class="pet-drawer" id="pet-drawer">
    <div class="drawer-header">
      <div>
        <h3 class="drawer-title">Pet Details</h3>
        <p class="drawer-subtitle">Review pet profile, listing, and adoption history.</p>
      </div>
      <button class="btn-modal-close" id="close-pet-drawer">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <div class="drawer-body" id="drawer-body-content">
      <!-- Injected by JavaScript on view click -->
    </div>

    <div class="drawer-footer" id="drawer-footer-actions">
      <!-- Toggle buttons injected by JavaScript -->
    </div>
  </div>

  <!-- Record Rescue Modal (Reused for header button) -->
  <div class="modal-backdrop" id="record-rescue-modal">
    <div class="modal-window">
      <div class="modal-head">
        <h3 class="modal-title">+ Record Shelter Rescue</h3>
        <p class="modal-subtitle">Record an animal rescued or received by DCVS and create its initial pet record.</p>
      </div>
      <button class="btn-modal-close" id="close-rescue-modal" title="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>

      <form id="rescue-form">
        <div class="modal-form-body">

          <!-- Section 1: ANIMAL INFORMATION -->
          <div>
            <div class="modal-section-label">ANIMAL INFORMATION</div>

            <div class="form-group" style="margin-bottom: 12px;">
              <label class="form-label">Display Photo</label>
              <div class="rescue-photo-upload" id="rescue-photo-upload" style="max-width: 240px;">
                <input type="file" id="rescue-photo-input" accept="image/*" style="display:none;">
                <div class="rescue-photo-placeholder" id="rescue-photo-placeholder">
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                  <span>Click to upload photo</span>
                </div>
                <div class="rescue-photo-preview" id="rescue-photo-preview" style="display:none;">
                  <img id="rescue-photo-img" src="" alt="Preview">
                  <button type="button" class="rescue-photo-remove" id="rescue-photo-remove" title="Remove photo">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </div>
              </div>
            </div>

            <div class="form-grid-2" style="margin-bottom: 12px;">
              <div class="form-group">
                <label class="form-label" for="rescue-pet-name">Animal / Pet Name <span class="req">*</span></label>
                <input type="text" id="rescue-pet-name" class="form-input" placeholder="e.g. Max" required>
              </div>
              <div class="form-group">
                <label class="form-label" for="rescue-species">Species <span class="req">*</span></label>
                <select id="rescue-species" class="form-select" required>
                  <option value="Dog" selected>Dog</option>
                  <option value="Cat">Cat</option>
                  <option value="Other">Other</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label" for="rescue-sex">Sex <span class="req">*</span></label>
                <select id="rescue-sex" class="form-select" required>
                  <option value="Female" selected>Female</option>
                  <option value="Male">Male</option>
                  <option value="Unknown">Unknown</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label" for="rescue-age">Age</label>
                <input type="text" id="rescue-age" class="form-input" value="2 years" placeholder="e.g. 2 years">
              </div>
            </div>
          </div>

          <!-- Section 2: RESCUE DETAILS -->
          <div>
            <div class="modal-section-label">RESCUE DETAILS</div>

            <div class="form-grid-2" style="margin-bottom: 12px;">
              <div class="form-group">
                <label class="form-label" for="rescue-source">Rescue Source <span class="req">*</span></label>
                <select id="rescue-source" class="form-select" required>
                  <option value="Barangay-Reported" selected>Barangay-Reported</option>
                  <option value="Resident Surrender">Resident Surrender</option>
                  <option value="Stray Roundup">Stray Roundup</option>
                  <option value="Field Operation">Field Operation</option>
                </select>
              </div>

              <div class="form-group">
                <label class="form-label" for="rescue-date">Rescue Date <span class="req">*</span></label>
                <input type="date" id="rescue-date" class="form-input" value="2026-09-20" required>
              </div>
            </div>

            <div class="form-grid-2">
              <div class="form-group">
                <label class="form-label" for="rescue-location">Rescue Location / Barangay</label>
                <select id="rescue-location" class="form-select">
                  <option value="Paliparan I" selected>Paliparan I</option>
                  <option value="Paliparan II">Paliparan II</option>
                  <option value="Paliparan III">Paliparan III</option>
                  <option value="Salawag">Salawag</option>
                  <option value="Burol Main">Burol Main</option>
                  <option value="Zone 1-A">Zone 1-A</option>
                  <option value="Zone 4">Zone 4</option>
                  <option value="Langkaan I">Langkaan I</option>
                  <option value="Sampaloc">Sampaloc</option>
                  <option value="Other">Other Barangay / Facility</option>
                </select>
              </div>

              <div class="form-group">
                <label class="form-label" for="rescue-current-location">Current Location <span class="req">*</span></label>
                <select id="rescue-current-location" class="form-select" required>
                  <option value="DCVS Shelter" selected>DCVS Shelter</option>
                  <option value="Clinic Quarantine">Clinic Quarantine</option>
                  <option value="Partner Foster Home">Partner Foster Home</option>
                  <option value="Barangay Holding Facility">Barangay Holding Facility</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Section 3: INTAKE NOTES -->
          <div>
            <div class="modal-section-label">INTAKE NOTES</div>
            <div class="form-group">
              <label class="form-label" for="rescue-notes">Intake Notes</label>
              <textarea id="rescue-notes" class="form-textarea" placeholder="Optional notes about the animal's condition, circumstances of rescue, or other intake details..."></textarea>
            </div>
          </div>

        </div>
        <div class="modal-footer">
          <button type="button" class="btn-cancel" id="cancel-rescue-modal">Cancel</button>
          <button type="submit" class="btn-submit">Record Rescue</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Multiple Animals Rescue Modal -->
  <div class="modal-backdrop" id="multi-rescue-modal">
    <div class="modal-window">
      <div class="modal-head">
        <h3 class="modal-title">+ Record Rescue Batch</h3>
        <p class="modal-subtitle">Record multiple animals rescued in a single event.</p>
      </div>
      <button class="btn-modal-close" id="close-multi-rescue-modal" title="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>

      <form id="multi-rescue-form">
        <div class="modal-form-body">

          <!-- Step 1: NUMBER OF ANIMALS -->
          <div>
            <div class="modal-section-label">NUMBER OF ANIMALS RESCUED</div>
            <div class="form-group">
              <input type="number" id="multi-rescue-count" class="form-input" min="2" max="200" placeholder="e.g. 8" required style="width: 80px; text-align: center;">
            </div>
          </div>

          <!-- Step 2: RESCUE DETAILS (shared across batch) -->
          <div>
            <div class="modal-section-label">RESCUE DETAILS</div>

            <div class="form-grid-2" style="margin-bottom: 12px;">
              <div class="form-group">
                <label class="form-label" for="multi-rescue-source">Rescue Source <span class="req">*</span></label>
                <select id="multi-rescue-source" class="form-select" required>
                  <option value="Barangay-Reported" selected>Barangay-Reported</option>
                  <option value="Resident Surrender">Resident Surrender</option>
                  <option value="Stray Roundup">Stray Roundup</option>
                  <option value="Field Operation">Field Operation</option>
                </select>
              </div>

              <div class="form-group">
                <label class="form-label" for="multi-rescue-date">Rescue Date <span class="req">*</span></label>
                <input type="date" id="multi-rescue-date" class="form-input" required>
              </div>
            </div>

            <div class="form-grid-2">
              <div class="form-group">
                <label class="form-label" for="multi-rescue-location">Rescue Location / Barangay</label>
                <select id="multi-rescue-location" class="form-select">
                  <option value="Paliparan I" selected>Paliparan I</option>
                  <option value="Paliparan II">Paliparan II</option>
                  <option value="Paliparan III">Paliparan III</option>
                  <option value="Salawag">Salawag</option>
                  <option value="Burol Main">Burol Main</option>
                  <option value="Zone 1-A">Zone 1-A</option>
                  <option value="Zone 4">Zone 4</option>
                  <option value="Langkaan I">Langkaan I</option>
                  <option value="Sampaloc">Sampaloc</option>
                  <option value="Other">Other Barangay / Facility</option>
                </select>
              </div>

              <div class="form-group">
                <label class="form-label" for="multi-rescue-current-location">Current Location <span class="req">*</span></label>
                <select id="multi-rescue-current-location" class="form-select" required>
                  <option value="DCVS Shelter" selected>DCVS Shelter</option>
                  <option value="Clinic Quarantine">Clinic Quarantine</option>
                  <option value="Partner Foster Home">Partner Foster Home</option>
                  <option value="Barangay Holding Facility">Barangay Holding Facility</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Step 3: INTAKE NOTES -->
          <div>
            <div class="modal-section-label">INTAKE NOTES</div>
            <div class="form-group">
              <textarea id="multi-rescue-notes" class="form-textarea" placeholder="Optional notes about the rescue event..."></textarea>
            </div>
          </div>

        </div>
        <div class="modal-footer">
          <button type="button" class="btn-cancel" id="cancel-multi-rescue-modal">Cancel</button>
          <button type="submit" class="btn-submit" id="multi-rescue-submit-btn">Record Rescue</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Rescue Batch Dossier Modal -->
  <div class="modal-backdrop" id="batch-detail-modal">
    <div class="modal-window" style="max-width: 560px;">
      <div class="modal-head">
        <h3 class="modal-title" id="batch-detail-title">Rescue Batch Dossier</h3>
        <p class="modal-subtitle" id="batch-detail-subtitle"></p>
      </div>
      <button class="btn-modal-close" id="close-batch-detail-modal" title="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>

      <div class="modal-form-body" id="batch-detail-body" style="padding-top:8px;">
        <!-- Populated by JS: summary card + animal slots -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" id="close-batch-detail-btn">Close</button>
      </div>
    </div>
  </div>

  <!-- Toast Notification Container -->
  <div class="toast-container" id="toast-container"></div>

  <!-- ==========================================================================
       SHADCN CONFIRMATION DIALOG (shared for Set as Available, Archive, Edit)
       ========================================================================== -->
  <div class="modal-backdrop" id="confirm-dialog" style="background:rgba(0,0,0,0.8);">
    <div class="modal-window" style="max-width:440px;">
      <button class="btn-modal-close" id="confirm-dialog-close" title="Close">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
      <div class="modal-head">
        <h3 class="modal-title" id="confirm-dialog-title"></h3>
        <p class="modal-subtitle" id="confirm-dialog-desc"></p>
      </div>
      <div class="modal-footer" style="justify-content:flex-end;">
        <button type="button" class="btn-cancel" id="confirm-dialog-cancel">Cancel</button>
        <button type="button" class="btn-submit" id="confirm-dialog-confirm"></button>
      </div>
    </div>
  </div>

  <!-- ==========================================================================
       JAVASCRIPT DATA & LOGIC
       ========================================================================== -->
  <script>
    window.adoptionPickupPeriodLabel = function (approvedPickupAt, applicationStatus) {
      if (applicationStatus === 'expired') return 'Expired / Unclaimed';
      if (applicationStatus !== 'approved' || !approvedPickupAt) return '—';
      const scheduledAt = new Date(String(approvedPickupAt).replace(' ', 'T'));
      if (Number.isNaN(scheduledAt.getTime())) return '—';
      const remaining = scheduledAt.getTime() + 3 * 24 * 60 * 60 * 1000 - Date.now();
      if (remaining <= 0) return 'Pickup period expired';
      const daysRemaining = Math.min(3, Math.ceil(remaining / (24 * 60 * 60 * 1000)));
      return daysRemaining + (daysRemaining === 1 ? ' day remaining' : (daysRemaining === 3 ? ' days allotted' : ' days remaining'));
    };

    document.addEventListener('DOMContentLoaded', () => {
      const STORAGE_KEY = 'DCVS_ADOPTION_DATA_V1';
      const PETS_KEY = 'DCVS_ADOPTION_PETS_V6';
      const INTAKE_KEY = 'DCVS_INTAKE_REGISTRY_V3';
      const BATCH_KEY_V2 = 'DCVS_RESCUE_BATCHES_V2';
      const SYNC_HANDLER = 'staff-pets-handler.php';
      const POOL_RESCUE_SOURCES = {
        'Barangay-Reported': 'barangay_reported',
        'Resident Surrender': 'resident_surrender',
        'Stray Roundup': 'stray_roundup',
        'Field Operation': 'field_operation'
      };
      const POOL_RESCUE_SOURCE_LABELS = {
        barangay_reported: 'Barangay-Reported',
        resident_surrender: 'Resident Surrender',
        stray_roundup: 'Stray Roundup',
        shelter_transfer: 'Shelter Transfer',
        confiscated: 'Confiscated',
        found_lost: 'Found/Lost Pet Unclaimed',
        field_operation: 'Field Operation'
      };

      function recordPoolRescueIntake(source, date, barangay, animalCount, notes) {
        const sourceCode = POOL_RESCUE_SOURCES[source];
        if (!sourceCode) return Promise.reject(new Error('Choose a valid rescue source.'));
        return fetch('record-rescue-handler.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            record_type: 'pool_intake',
            source: sourceCode,
            rescue_date: date,
            barangay_name: barangay,
            animal_count: animalCount,
            notes: notes || ''
          })
        }).then(response => response.json()).then(result => {
          if (!result || !result.success) throw new Error((result && result.error) || 'Could not record rescue intake.');
          return Number(result.intake_id);
        });
      }

      // One-time clear of old data from previous versions
      ['DCVS_ADOPTION_PETS_V5','DCVS_INTAKE_REGISTRY_V2','DCVS_RESCUE_BATCHES_V1'].forEach(k => {
        if (localStorage.getItem(k)) localStorage.removeItem(k);
      });

      // Sync a pool pet to the pet_listings DB
      // Prevent duplicate in-flight syncs per pet
      const _pendingSyncs = new Set();
      function syncToDB(pet) {
        if (_pendingSyncs.has(pet.id)) return;
        _pendingSyncs.add(pet.id);

        const fd = new FormData();
        fd.append('action', 'sync_from_pool');
        fd.append('pool_id', pet.id);
        fd.append('name', pet.name);
        fd.append('species', pet.species);
        fd.append('breed', pet.breed);
        fd.append('age', pet.age);
        fd.append('gender', pet.gender);
        fd.append('rescue_date', pet.fullRescueDate || pet.rescueDate || '');
        fd.append('image', pet.image || '');
        fd.append('adoption_status', pet.adoptionStatus);
        fd.append('listing_status', pet.listingStatus);
        fd.append('listing_id', pet.listingId || 0);
        fd.append('rescue_intake_id', pet.rescueIntakeId || 0);
        fd.append('notes', pet.notes || '');
        fd.append('readiness', JSON.stringify(pet.readiness || {}));
        fetch(SYNC_HANDLER, { method: 'POST', body: fd })
          .then(r => r.json())
          .then(res => {
            if (res.success) {
              const pets = getPoolPets();
              const target = pets.find(p => p.id === pet.id);
              if (target) {
                target.image = res.photo_url || target.image;
                if (!target.listingId) target.listingId = res.listing_id;
                savePoolPets(pets);
              }
            }
          })
          .catch(() => {})
          .finally(() => { _pendingSyncs.delete(pet.id); });
      }

      // Default Registered / Rescued Animals that can be chosen for "Add to Adoption Pool"
      const INTAKE_REGISTRY = [];

      // Merge custom rescues recorded from DCVS_INTAKE_REGISTRY_V1
      try {
        const storedIntakes = JSON.parse(localStorage.getItem(INTAKE_KEY)) || [];
        storedIntakes.forEach(ci => {
          if (!INTAKE_REGISTRY.some(r => r.id === ci.id)) {
            INTAKE_REGISTRY.unshift(ci);
          }
        });
      } catch(e) {}

      // Remove stale test entry (Pudge) from selection records — permanent
      try {
        const storedIntakes2 = JSON.parse(localStorage.getItem(INTAKE_KEY)) || [];
        const cleanedIntakes = storedIntakes2.filter(ci => String(ci.name || '').toLowerCase() !== 'pudge');
        if (cleanedIntakes.length !== storedIntakes2.length) {
          localStorage.setItem(INTAKE_KEY, JSON.stringify(cleanedIntakes));
        }
      } catch(e) {}
      const _pudgeIdx = INTAKE_REGISTRY.findIndex(p => String(p.name || '').toLowerCase() === 'pudge');
      if (_pudgeIdx >= 0) INTAKE_REGISTRY.splice(_pudgeIdx, 1);

      // Initial Default Pool Pets — populated from DB via initFromDB()
      const DEFAULT_POOL_PETS = [];

      // Retrieve or init pets — dedup by name+species+gender, keeping the first (most recently added/updated)
      function getPoolPets() {
        try {
          const stored = localStorage.getItem(PETS_KEY);
          const pets = stored ? JSON.parse(stored) : [...DEFAULT_POOL_PETS];
          const identityMap = new Map();
          for (let i = pets.length - 1; i >= 0; i--) {
            const p = pets[i];
            const key = `${p.name}|${p.species}|${p.gender}`;
            identityMap.set(key, p);
          }
          const deduped = Array.from(identityMap.values());
          if (deduped.length !== pets.length) {
            savePoolPets(deduped);
          }
          return deduped;
        } catch (e) {}
        return [...DEFAULT_POOL_PETS];
      }

      function savePoolPets(pets) {
        localStorage.setItem(PETS_KEY, JSON.stringify(pets));
      }

      function samePoolIdentity(a, b) {
        const normalize = value => String(value || '').trim().toLowerCase();
        return a && b &&
          normalize(a.name) === normalize(b.name) &&
          normalize(a.species) === normalize(b.species) &&
          normalize(a.gender) === normalize(b.gender);
      }

      function isPendingReviewIntake(item) {
        const status = String(item.adoptionStatus || item.status || 'Pending Review').trim().toLowerCase();
        const listingStatus = String(item.listingStatus || item.listing_status || '').trim().toLowerCase();
        return (status === 'pending review' || status === 'pending') &&
          listingStatus !== 'published' &&
          listingStatus !== 'available' &&
          listingStatus !== 'adopted';
      }

      function isEligiblePoolIntake(item, poolPets) {
        return isPendingReviewIntake(item) &&
          item.poolAdded !== true &&
          !poolPets.some(poolPet => poolPet.intakeId === item.id || samePoolIdentity(poolPet, item));
      }

      function markPoolIntakeAsAdded(poolPet) {
        const intake = INTAKE_REGISTRY.find(item =>
          item.id === poolPet.intakeId ||
          item.id === poolPet.id ||
          samePoolIdentity(item, poolPet)
        );
        if (intake) {
          intake.poolAdded = true;
          localStorage.setItem(INTAKE_KEY, JSON.stringify(INTAKE_REGISTRY));
        }
      }

      // Pool starts empty — no auto-import from DB
      // Pets are added via "Add to Adoption Pool" or "Record Rescue" forms

      // Format a DB timestamp/date (YYYY-MM-DD...) as "MMM DD, YYYY" (matches PHP date('M d, Y'))
      function fmtAdoptedDate(s) {
        if (!s) return '';
        const p = String(s).slice(0, 10).split('-');
        if (p.length < 3 || !p[0] || !p[1] || !p[2]) return '';
        const M = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const mi = parseInt(p[1], 10) - 1;
        return (M[mi] || p[1]) + ' ' + p[2] + ', ' + p[0];
      }

      // Pull adoption status + adopted date from DB for pets adopted via
      // the resident adoption flow / staff actions (localStorage may be stale)
      function syncAdoptedFromDB() {
        return fetch(SYNC_HANDLER + '?action=get_all')
          .then(r => r.json())
          .then(res => {
            if (!(res.success && Array.isArray(res.data))) return;
            const byId = {}, byName = {};
            res.data.forEach(row => {
              if (row.listing_id != null) byId[String(row.listing_id)] = row;
              const nk = String(row.name || '').toLowerCase() + '|' + String(row.species || '').toLowerCase();
              byName[nk] = row;
            });
            const pets = getPoolPets();
            let changed = false;
            const visiblePets = [];
            pets.forEach(p => {
              const row = byId[String(p.listingId || '')] || byName[String(p.name || '').toLowerCase() + '|' + String(p.species || '').toLowerCase()];
              if (!row) {
                visiblePets.push(p);
                return;
              }
              if (row.status === 'removed') {
                changed = true;
                return;
              }
              visiblePets.push(p);
              const applicationId = Number(row.adoption_application_id || 0);
              const applicationStatus = row.adoption_application_status || '';
              if (Number(p.adoptionApplicationId || 0) !== applicationId) { p.adoptionApplicationId = applicationId; changed = true; }
              if (p.adoptionApplicationStatus !== applicationStatus) { p.adoptionApplicationStatus = applicationStatus; changed = true; }
              if ((p.approvedPickupAt || '') !== (row.approved_pickup_at || '')) { p.approvedPickupAt = row.approved_pickup_at || ''; changed = true; }
              if (Number(row.rescue_intake_id) > 0 && Number(p.rescueIntakeId) !== Number(row.rescue_intake_id)) {
                p.rescueIntakeId = Number(row.rescue_intake_id);
                changed = true;
              }
              const sourceLabel = POOL_RESCUE_SOURCE_LABELS[row.rescue_source];
              if (sourceLabel && p.rescueSource !== sourceLabel) { p.rescueSource = sourceLabel; changed = true; }
              const intakeDate = row.intake_rescue_date || '';
              if (intakeDate && p.fullRescueDate !== intakeDate) { p.fullRescueDate = intakeDate; changed = true; }
              if (row.adopter_name && p.adopterName !== row.adopter_name) { p.adopterName = row.adopter_name; changed = true; }
              if (row.adopter_reference_id && p.adopterReferenceId !== row.adopter_reference_id) { p.adopterReferenceId = row.adopter_reference_id; changed = true; }
              if (row.adopter_barangay && p.adopterBarangay !== row.adopter_barangay) { p.adopterBarangay = row.adopter_barangay; changed = true; }
              if (row.status === 'reserved') {
                if (p.adoptionStatus !== 'Reserved') { p.adoptionStatus = 'Reserved'; changed = true; }
                if (p.listingStatus !== 'Not Listed') { p.listingStatus = 'Not Listed'; changed = true; }
                if (p.adoptedDate) { p.adoptedDate = ''; changed = true; }
                if (p.adopterName) { p.adopterName = ''; changed = true; }
                if (p.adopterReferenceId) { p.adopterReferenceId = ''; changed = true; }
                if (p.adopterBarangay) { p.adopterBarangay = ''; changed = true; }
                return;
              }
              if (row.status === 'available' && p.adoptionStatus === 'Adopted') {
                p.adoptionStatus = 'Available';
                p.listingStatus = 'Published';
                p.adoptedDate = '';
                p.adopterName = '';
                p.adopterReferenceId = '';
                p.adopterBarangay = '';
                changed = true;
                return;
              }
              if (p.adoptionStatus === 'Reserved' && row.status === 'available') {
                p.adoptionStatus = 'Available';
                p.listingStatus = 'Published';
                changed = true;
              }
              if (row.status !== 'adopted') return;
              if (p.adoptionStatus !== 'Adopted') { p.adoptionStatus = 'Adopted'; changed = true; }
              if (p.listingStatus === 'Published') { p.listingStatus = 'Not Listed'; changed = true; }
              const d = fmtAdoptedDate(row.adopted_at);
              if (d && p.adoptedDate !== d) { p.adoptedDate = d; changed = true; }
            });
            if (changed) { savePoolPets(visiblePets); refreshCounts(); renderTable(); }
          })
          .catch(() => {});
      }

      // Load pets from DB when localStorage is empty (after key bump or first visit)
      function loadFromDB() {
        return fetch(SYNC_HANDLER + '?action=get_all')
          .then(r => r.json())
          .then(res => {
            if (res.success && Array.isArray(res.data)) {
              const pets = res.data.filter(row => row.status !== 'removed').map(row => {
                const statusMap = { adopted: 'Adopted', reserved: 'Reserved', available: 'Available', withdrawn: 'Pending Review' };
                const adoptionStatus = statusMap[row.status] || 'Pending Review';
                const listingStatus = row.status === 'available' ? 'Published' : 'Not Listed';
                return {
                  id: 'pet-' + (row.listing_id || Date.now()),
                  name: row.name,
                  species: row.species,
                  breed: row.breed || '',
                  gender: row.gender || '',
                  age: row.age || '',
                  rescueSource: POOL_RESCUE_SOURCE_LABELS[row.rescue_source] || 'DCVS Shelter',
                  rescueDate: row.intake_rescue_date || row.rescued_date || '',
                  fullRescueDate: row.intake_rescue_date || row.rescued_date || '',
                  rescueIntakeId: Number(row.rescue_intake_id || 0),
                  adoptionStatus: adoptionStatus,
                  listingStatus: listingStatus,
                  image: row.photo_url || '',
                  readyDate: row.rescued_date || '',
                  notes: row.description || '',
                  listingId: row.listing_id,
                  adoptionApplicationId: Number(row.adoption_application_id || 0),
                  adoptionApplicationStatus: row.adoption_application_status || '',
                  approvedPickupAt: row.approved_pickup_at || '',
                  adopterName: row.adopter_name || '',
                  adopterReferenceId: row.adopter_reference_id || '',
                  adopterBarangay: row.adopter_barangay || '',
                  adoptedDate: row.status === 'adopted' ? fmtAdoptedDate(row.adopted_at) : '',
                  readiness: { vaccination: 'Not specified', health: 'Not assessed', spayNeuter: 'Not specified', temperament: 'Not assessed' }
                };
              });
              savePoolPets(pets);
              return pets;
            }
            return [];
          })
          .catch(() => []);
      }

      // Counts management
      function refreshCounts() {
        const pets = getPoolPets();

        const pendingCount = pets.filter(p => p.adoptionStatus === 'Pending Review').length;
        const availableCount = pets.filter(p => p.adoptionStatus === 'Available' && p.listingStatus !== 'Published').length;
        const publishedCount = pets.filter(p => p.listingStatus === 'Published').length;
        const adoptedCount = pets.filter(p => p.adoptionStatus === 'Adopted').length;

        document.getElementById('count-pending').textContent = pendingCount;
        document.getElementById('count-available').textContent = availableCount;
        document.getElementById('count-published').textContent = publishedCount;
        document.getElementById('count-adopted').textContent = adoptedCount;
      }

      // Table Rendering & Filters
      const tableBody = document.getElementById('pool-table-body');
      const searchInput = document.getElementById('table-search-input');
      const filterSpecies = document.getElementById('filter-species');
      const filterSource = document.getElementById('filter-source');
      const filterAdoptionStatus = document.getElementById('filter-adoption-status');
      const filterListingStatus = document.getElementById('filter-listing-status');
      const emptyState = document.getElementById('table-empty-state');

      let currentFilterCard = null;
      let poolPage = 1;
      let poolPerPage = 10;

      var sortCol = '';
      var sortDir = 'asc';

      window.sortBy = function(col) {
        if (sortCol === col) { sortDir = sortDir === 'asc' ? 'desc' : 'asc'; }
        else { sortCol = col; sortDir = 'asc'; }

        document.querySelectorAll('.data-table thead .th-sort').forEach(function(btn) {
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

        renderTable();
      };

      window.setPoolPerPage = function(value) {
        poolPerPage = parseInt(value, 10) || 10;
        poolPage = 1;
        renderTable();
      };

      function renderPoolPagination(total) {
        const pages = Math.max(1, Math.ceil(total / poolPerPage));
        poolPage = Math.min(Math.max(1, poolPage), pages);
        const label = document.getElementById('poolPageLabel');
        const first = document.getElementById('poolPageFirst');
        const prev = document.getElementById('poolPagePrev');
        const next = document.getElementById('poolPageNext');
        const last = document.getElementById('poolPageLast');
        if (label) label.textContent = 'Page ' + poolPage + ' of ' + pages;
        if (first) { first.disabled = poolPage <= 1; first.onclick = () => { poolPage = 1; renderTable(); }; }
        if (prev) { prev.disabled = poolPage <= 1; prev.onclick = () => { poolPage = Math.max(1, poolPage - 1); renderTable(); }; }
        if (next) { next.disabled = poolPage >= pages; next.onclick = () => { poolPage = Math.min(pages, poolPage + 1); renderTable(); }; }
        if (last) { last.disabled = poolPage >= pages; last.onclick = () => { poolPage = pages; renderTable(); }; }
      }

      function getFilteredPoolPets() {
        const pets = getPoolPets();
        const search = searchInput.value.toLowerCase().trim();
        const species = filterSpecies.value;
        const source = filterSource.value;
        const adStatus = filterAdoptionStatus.value;
        const listStatus = filterListingStatus.value;

        const filtered = pets.filter(p => {
          // Search matching
          const matchesSearch = !search || 
            p.name.toLowerCase().includes(search) || 
            p.breed.toLowerCase().includes(search) ||
            p.rescueSource.toLowerCase().includes(search);

          // Dropdown filters
          const matchesSpecies = species === 'all' || p.species === species;
          const matchesSource = source === 'all' || p.rescueSource === source;
          const matchesAdStatus = adStatus === 'all' || p.adoptionStatus === adStatus;
          const matchesListStatus = listStatus === 'all' || p.listingStatus === listStatus;

          return matchesSearch && matchesSpecies && matchesSource && matchesAdStatus && matchesListStatus;
        });

        if (sortCol) {
          var dir = sortDir === 'desc' ? -1 : 1;
          filtered.sort(function(a, b) {
            var av = a[sortCol] || '';
            var bv = b[sortCol] || '';
            if (typeof av === 'string') av = av.toLowerCase();
            if (typeof bv === 'string') bv = bv.toLowerCase();
            return av < bv ? -dir : av > bv ? dir : 0;
          });
        }
        return filtered;
      }

      window.exportAdoptionPoolCSV = function() {
        function csvCell(value) {
          let text = String(value == null ? '' : value).replace(/\s+/g, ' ').trim();
          if (/^[=+\-@\t\r]/.test(text)) text = "'" + text;
          return '"' + text.replace(/"/g, '""') + '"';
        }
        const headers = ['Pet', 'Gender', 'Breed', 'Age', 'Species', 'Rescue Source', 'Rescue Date', 'Adoption Status', 'Confirmed/Adopted Date', 'Pickup Period', 'Listing'];
        const rows = [headers.map(csvCell).join(',')];
        getFilteredPoolPets().forEach(pet => {
          rows.push([
            pet.name, pet.gender, pet.breed, pet.age, pet.species, pet.rescueSource,
            pet.rescueDate, pet.adoptionStatus,
            pet.adoptedDate || '—',
            adoptionPickupPeriodLabel(pet.approvedPickupAt, pet.adoptionApplicationStatus),
            pet.listingStatus === 'Published' ? 'Published' : 'Not Listed'
          ].map(csvCell).join(','));
        });
        const blob = new Blob(['\uFEFF' + rows.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'adoption-pool-' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
      };

      function renderTable() {
        const filtered = getFilteredPoolPets();
        renderPoolPagination(filtered.length);
        const pageStart = (poolPage - 1) * poolPerPage;
        const pageItems = filtered.slice(pageStart, pageStart + poolPerPage);

        tableBody.innerHTML = '';
        updatePoolBulkBar();

        if (filtered.length === 0) {
          emptyState.style.display = 'flex';
          return;
        }

        emptyState.style.display = 'none';

        pageItems.forEach(pet => {
          const tr = document.createElement('tr');
          tr.classList.add('pool-clickable-row');
          tr.dataset.petId = pet.id;
          tr.tabIndex = 0;
          tr.setAttribute('aria-label', 'View details for ' + pet.name);
          
          // Adoption status pill class
          let adStatusClass = 'available';
          if (pet.adoptionStatus === 'Pending Review') adStatusClass = 'pending';
          else if (pet.adoptionStatus === 'Adopted') adStatusClass = 'adopted';
          else if (pet.adoptionStatus === 'Reserved') adStatusClass = 'reserved';
          else if (pet.adoptionStatus === 'On Hold') adStatusClass = 'pending';

          // Listing status text
          const listStatusLabel = pet.listingStatus === 'Published' ? 'Published' : 'Not Listed';

          tr.innerHTML = `
            <td><input type="checkbox" class="sd-table-cb row-cb" value="${pet.id}" onclick="event.stopPropagation();updatePoolBulkBar()" /></td>
            <td>
              <div class="pet-cell">
                <img src="${pet.image}" alt="${pet.name}" class="pet-thumbnail">
                <div class="pet-meta-text">
                  <div class="pet-name-title">${pet.name}</div>
                </div>
              </div>
            </td>
            <td>${pet.gender}</td>
            <td>${pet.breed}</td>
            <td>${pet.age}</td>
            <td>${pet.species}</td>
            <td>${pet.rescueSource}</td>
            <td>${pet.rescueDate}</td>
            <td>
              <span class="status-pill ${adStatusClass}">${pet.adoptionStatus}</span>
            </td>
            <td>${pet.adoptedDate || '—'}</td>
            <td>${adoptionPickupPeriodLabel(pet.approvedPickupAt, pet.adoptionApplicationStatus)}</td>
            <td>${listStatusLabel}</td>
            <td class="sd-td sd-td-action"><button class="sd-row-actions" title="More options" data-pet-id="${pet.id}"><span class="sr-only">Open menu</span><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/></svg></button></td>
          `;

          tableBody.appendChild(tr);
        });
      }

      /* ── Row menu (shared popup, same pattern as All Reports) ── */
      (function () {
        var poolTableBody = document.getElementById('pool-table-body');
        if (!poolTableBody) return;

        poolTableBody.addEventListener('click', function (e) {
          if (e.target.closest('input, button, a, select, .action-menu')) return;
          var row = e.target.closest('tr[data-pet-id]');
          if (row) openPetDrawer(row.dataset.petId);
        });
        poolTableBody.addEventListener('keydown', function (e) {
          if ((e.key !== 'Enter' && e.key !== ' ') || e.target.closest('input, button, a, select')) return;
          var row = e.target.closest('tr[data-pet-id]');
          if (!row) return;
          e.preventDefault();
          openPetDrawer(row.dataset.petId);
        });

        poolTableBody.addEventListener('click', function (e) {
          var btn = e.target.closest('.sd-row-actions');
          if (!btn) return;
          e.stopPropagation();
          var petId = btn.getAttribute('data-pet-id');
          if (!petId) return;
          closePoolActions();
          var pets = getPoolPets();
          var pet = pets.find(function (p) { return p.id === petId; });
          if (!pet) return;

          var pop = document.getElementById('poolRowMenuPop');
          var items = '';

          items += '<button class="row-menu-item" data-action="view" data-pet-id="' + pet.id + '"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View Details</button>';
          if (pet.adoptionStatus !== 'Adopted' && pet.adoptionStatus !== 'Reserved') {
            items += '<button class="row-menu-item" data-action="edit" data-pet-id="' + pet.id + '"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit</button>';
          }
          if (pet.adoptionStatus === 'Pending Review') {
            items += '<button class="row-menu-item" data-action="set-available" data-pet-id="' + pet.id + '"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>Set as Available</button>';
          }
          if (pet.adoptionStatus === 'Available' && pet.listingStatus !== 'Published') {
            items += '<button class="row-menu-item" data-action="publish" data-pet-id="' + pet.id + '"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>Publish</button>';
          }
          if (pet.adoptionStatus === 'Available' && pet.listingStatus === 'Published') {
            items += '<button class="row-menu-item" data-action="unpublish" data-pet-id="' + pet.id + '"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>Unpublish</button>';
          }
          if (pet.adoptionStatus !== 'Reserved') {
            items += '<button class="row-menu-item danger" data-action="archive" data-pet-id="' + pet.id + '" data-pet-name="' + (pet.name || '').replace(/"/g, '&quot;') + '"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>Archive</button>';
          }

          pop.innerHTML = items;
          var rect = btn.getBoundingClientRect();
          pop.style.position = 'fixed';
          var menuWidth = pop.offsetWidth || 220;
          var menuHeight = pop.offsetHeight || 200;
          var left = Math.max(8, Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - 8));
          var top = rect.bottom + 6;
          if (top + menuHeight > window.innerHeight - 8 && rect.top - menuHeight - 6 >= 8) {
            top = rect.top - menuHeight - 6;
          }
          pop.style.top = top + 'px';
          pop.style.left = left + 'px';
          pop.classList.add('open');
          setTimeout(function () { document.addEventListener('click', closePoolActions); }, 0);
        });
      })();

      document.getElementById('poolRowMenuPop').addEventListener('click', function (e) {
        var item = e.target.closest('.row-menu-item');
        if (!item) return;
        e.stopPropagation();
        var action = item.getAttribute('data-action');
        var petId = item.getAttribute('data-pet-id');
        var pets = getPoolPets();
        var pet = pets.find(function (p) { return p.id === petId; });

        closePoolActions();

        if (action === 'view' && pet) openPetDrawer(petId);
        else if (action === 'edit' && pet) openEditPetDetails(pet);
        else if (action === 'set-available' && pet) showConfirmDialog({
          title: 'Set ' + pet.name + ' as Available?',
          desc: 'Are you sure you want to set this animal as available for adoption? This will make the animal eligible to be listed for adoption.',
          confirmLabel: 'Set as Available',
          onConfirm: () => setAsAvailable(pet)
        });
        else if ((action === 'publish' || action === 'unpublish') && pet) showConfirmDialog({
          title: action === 'publish' ? 'Publish Animal?' : 'Unpublish Animal?',
          desc: action === 'publish'
            ? 'Are you sure you want to publish ' + pet.name + ' on the public adoption page?'
            : 'Are you sure you want to unpublish ' + pet.name + '? The animal will be hidden from the public adoption page.',
          confirmLabel: action === 'publish' ? 'Publish' : 'Unpublish',
          onConfirm: () => toggleListingStatus(pet)
        });
        else if (action === 'archive' && pet) {
          showConfirmDialog({
            title: 'Archive Animal?',
            desc: 'Are you sure you want to archive this animal? Archived animals will no longer appear in the active adoption pool.',
            confirmLabel: 'Archive',
            variant: 'danger',
            onConfirm: () => {
              var pets = getPoolPets();
              var idx = pets.findIndex(function (p) { return p.id === petId; });
              if (idx < 0) return;
              pets[idx].listingStatus = 'Archived';
              markPoolIntakeAsAdded(pets[idx]);
              syncToDB(pets[idx]);
              pets.splice(idx, 1);
              savePoolPets(pets);
              refreshCounts();
              renderTable();
              showToast('Animal Archived', { type: 'success', description: (item.getAttribute('data-pet-name') || pet.name) + ' has been removed from the active pool.' });
            }
          });
        }
      });

      function closePoolActions() {
        var pop = document.getElementById('poolRowMenuPop');
        if (pop) pop.classList.remove('open');
        document.removeEventListener('click', closePoolActions);
      }

      /* ── Checkbox bulk selection + quick actions (same pattern as All Reports > Barangay Portal) ── */
      function togglePoolSelectAll(cb) {
        document.querySelectorAll('#pool-table-body .row-cb').forEach(b => { b.checked = cb.checked; });
        updatePoolBulkBar();
      }

      function updatePoolBulkBar() {
        const checked = document.querySelectorAll('#pool-table-body .row-cb:checked');
        const countEl = document.getElementById('poolBulkCount');
        if (countEl) countEl.textContent = checked.length;
        const bar = document.getElementById('poolBulkBar');
        if (bar) bar.classList.toggle('active', checked.length > 0);
        const all = document.querySelectorAll('#pool-table-body .row-cb');
        const sel = document.getElementById('poolSelectAll');
        if (sel) {
          sel.checked = all.length > 0 && checked.length === all.length;
          sel.indeterminate = checked.length > 0 && checked.length < all.length;
        }
      }

      function getSelectedPoolIds() {
        const ids = [];
        document.querySelectorAll('#pool-table-body .row-cb:checked').forEach(cb => ids.push(String(cb.value)));
        return ids;
      }

      function readinessIncomplete(p) {
        const r = p.readiness || {};
        return (!r.vaccination || r.vaccination === 'Not specified') ||
          (!r.health || r.health === 'Not assessed') ||
          (!r.spayNeuter || r.spayNeuter === 'Not specified') ||
          (!r.temperament || r.temperament === 'Not assessed');
      }

      /* Sequential bulk runner — one shadcn confirm dialog per eligible animal, with progress.
         Only eligible animals are queued; ineligible selected animals are skipped (Barangay Portal logic). */
      let bulkSeq = null;

      function startBulkSequence(cfg) {
        bulkSeq = cfg;
        bulkSeq.applied = 0;
        showBulkStep();
      }

      function showBulkStep() {
        if (!bulkSeq) return;
        if (!bulkSeq.queue.length) { finishBulkSequence(); return; }
        const idx = bulkSeq.total - bulkSeq.queue.length + 1;
        let desc = bulkSeq.desc;
        if (bulkSeq.total > 1) desc += ' Progress: ' + idx + ' of ' + bulkSeq.total + ' eligible animal(s).';
        if (bulkSeq.skipped && idx === 1) desc += ' ' + bulkSeq.skipped + ' selected ineligible animal(s) will be skipped.';
        showConfirmDialog({
          title: bulkSeq.title,
          desc: desc,
          confirmLabel: bulkSeq.confirmLabel,
          variant: bulkSeq.variant,
          onConfirm: () => {
            const pet = bulkSeq.queue.shift();
            confirmDialogOnHide = null; // programmatic hide after confirm — not a cancel
            if (pet && bulkSeq.apply(pet)) bulkSeq.applied++;
            setTimeout(() => {
              if (bulkSeq && bulkSeq.queue.length) showBulkStep();
              else finishBulkSequence();
            }, 0);
          },
          onHide: cancelBulkSequence
        });
      }

      function finishBulkSequence() {
        const cfg = bulkSeq;
        bulkSeq = null;
        confirmDialogOnHide = null;
        savePoolPets(cfg.pets);
        refreshCounts();
        renderTable();
        showToast(cfg.doneTitle, { type: cfg.doneType || 'success', description: cfg.doneDesc(cfg) });
      }

      function cancelBulkSequence() {
        if (!bulkSeq) return;
        const cfg = bulkSeq;
        bulkSeq = null;
        if (cfg.applied > 0) {
          savePoolPets(cfg.pets);
          refreshCounts();
          renderTable();
          showToast(cfg.doneTitle, {
            type: 'info',
            description: 'Applied to ' + cfg.applied + ' of ' + cfg.total + ' eligible animal(s). The remaining were cancelled.'
          });
        }
      }

      function bulkPublish() {
        const pets = getPoolPets();
        const ids = getSelectedPoolIds();
        if (!ids.length) { showToast('Select at least one animal.', { type: 'info' }); return; }
        const selected = pets.filter(p => ids.indexOf(String(p.id)) !== -1);
        const eligible = selected.filter(p => p.adoptionStatus === 'Available' && p.listingStatus !== 'Published' && p.listingStatus !== 'Archived');
        if (!eligible.length) {
          showToast('Nothing to Publish', { type: 'info', description: 'Only Available animals that are not yet published can be published. Other selected animals are ignored.' });
          return;
        }
        startBulkSequence({
          queue: eligible.slice(), pets: pets,
          total: eligible.length, skipped: selected.length - eligible.length,
          title: 'Publish Animal?', confirmLabel: 'Publish',
          desc: 'Are you sure you want to publish this animal on the public adoption page?',
          doneTitle: 'Published', doneType: 'success',
          doneDesc: c => c.applied + ' of ' + c.total + ' eligible selected animal(s) published.' + (c.skipped ? ' ' + c.skipped + ' skipped (not eligible).' : ''),
          apply: p => { p.listingStatus = 'Published'; syncToDB(p); return true; }
        });
      }

      function bulkUnpublish() {
        const pets = getPoolPets();
        const ids = getSelectedPoolIds();
        if (!ids.length) { showToast('Select at least one animal.', { type: 'info' }); return; }
        const selected = pets.filter(p => ids.indexOf(String(p.id)) !== -1);
        const eligible = selected.filter(p => p.listingStatus === 'Published');
        if (!eligible.length) {
          showToast('Nothing to Unpublish', { type: 'info', description: 'Only published animals can be unpublished. Other selected animals are ignored.' });
          return;
        }
        startBulkSequence({
          queue: eligible.slice(), pets: pets,
          total: eligible.length, skipped: selected.length - eligible.length,
          title: 'Unpublish Animal?', confirmLabel: 'Unpublish',
          desc: 'Are you sure you want to unpublish this animal? It will be hidden from the public adoption page.',
          doneTitle: 'Unpublished', doneType: 'info',
          doneDesc: c => c.applied + ' of ' + c.total + ' eligible selected animal(s) unpublished.' + (c.skipped ? ' ' + c.skipped + ' skipped (not eligible).' : ''),
          apply: p => { p.listingStatus = 'Not Listed'; syncToDB(p); return true; }
        });
      }

      function bulkSetAvailable() {
        const pets = getPoolPets();
        const ids = getSelectedPoolIds();
        if (!ids.length) { showToast('Select at least one animal.', { type: 'info' }); return; }
        const selected = pets.filter(p => ids.indexOf(String(p.id)) !== -1);
        const eligible = selected.filter(p => p.adoptionStatus === 'Pending Review' && !readinessIncomplete(p));
        if (!eligible.length) {
          showToast('Nothing to Set as Available', { type: 'info', description: 'Only animals in Pending Review with a complete readiness assessment can be set as Available. Other selected animals are ignored.' });
          return;
        }
        startBulkSequence({
          queue: eligible.slice(), pets: pets,
          total: eligible.length, skipped: selected.length - eligible.length,
          title: 'Set Animal as Available?', confirmLabel: 'Set as Available',
          desc: 'Are you sure you want to set this animal as available for adoption? This will make the animal eligible to be listed for adoption.',
          doneTitle: 'Set as Available', doneType: 'success',
          doneDesc: c => c.applied + ' of ' + c.total + ' eligible selected animal(s) set as Available.' + (c.skipped ? ' ' + c.skipped + ' skipped (not Pending Review or readiness incomplete).' : ''),
          apply: p => { p.adoptionStatus = 'Available'; p.listingStatus = 'Not Listed'; syncToDB(p); return true; }
        });
      }

      function bulkArchive() {
        const pets = getPoolPets();
        const ids = getSelectedPoolIds();
        if (!ids.length) { showToast('Select at least one animal.', { type: 'info' }); return; }
        const selected = pets.filter(p => ids.indexOf(String(p.id)) !== -1);
        if (!selected.length) {
          showToast('Nothing to Archive', { type: 'info', description: 'No valid animals selected.' });
          return;
        }
        startBulkSequence({
          queue: selected.slice(), pets: pets,
          total: selected.length, skipped: 0,
          title: 'Archive Animal?', confirmLabel: 'Archive', variant: 'danger',
          desc: 'Are you sure you want to archive this animal? Archived animals will no longer appear in the active adoption pool.',
          doneTitle: 'Archived', doneType: 'success',
          doneDesc: c => c.applied + ' animal(s) archived. They have been removed from the active pool.',
          apply: p => {
            p.listingStatus = 'Archived';
            markPoolIntakeAsAdded(p);
            syncToDB(p);
            const i = pets.findIndex(x => x.id === p.id);
            if (i >= 0) pets.splice(i, 1);
            return true;
          }
        });
      }

      window.togglePoolSelectAll = togglePoolSelectAll;
      window.updatePoolBulkBar = updatePoolBulkBar;
      window.bulkPublish = bulkPublish;
      window.bulkUnpublish = bulkUnpublish;
      window.bulkSetAvailable = bulkSetAvailable;
      window.bulkArchive = bulkArchive;

      // Filter events
      const btnResetFilters = document.getElementById('btn-reset-filters');
      function updateResetBtn() {
        const hasFilter = searchInput.value || filterSpecies.value !== 'all' || filterSource.value !== 'all' || filterAdoptionStatus.value !== 'all' || filterListingStatus.value !== 'all';
        btnResetFilters.style.display = hasFilter ? '' : 'none';
      }
      searchInput.addEventListener('input', () => { renderTable(); updateResetBtn(); });
      filterSpecies.addEventListener('change', () => { renderTable(); updateResetBtn(); });
      filterSource.addEventListener('change', () => { renderTable(); updateResetBtn(); });
      filterAdoptionStatus.addEventListener('change', () => { renderTable(); updateResetBtn(); });
      filterListingStatus.addEventListener('change', () => { renderTable(); updateResetBtn(); });

      btnResetFilters.addEventListener('click', () => {
        searchInput.value = '';
        filterSpecies.value = 'all';
        filterSource.value = 'all';
        filterAdoptionStatus.value = 'all';
        filterListingStatus.value = 'all';
        document.querySelectorAll('.kpi-card').forEach(c => c.classList.remove('active-filter'));
        renderTable();
        updateResetBtn();
      });

      // Quick Card Filters
      document.querySelectorAll('.kpi-card').forEach(card => {
        card.addEventListener('click', () => {
          const listFilter = card.dataset.filterListing;
          const statusFilter = card.dataset.filterStatus;

          // Toggle
          if (card.classList.contains('active-filter')) {
            card.classList.remove('active-filter');
            filterListingStatus.value = 'all';
            filterAdoptionStatus.value = 'all';
          } else {
        document.querySelectorAll('.kpi-card').forEach(c => c.classList.remove('active-filter'));
            card.classList.add('active-filter');

            if (listFilter) filterListingStatus.value = listFilter;
            else filterListingStatus.value = 'all';

            if (statusFilter) filterAdoptionStatus.value = statusFilter;
            else filterAdoptionStatus.value = 'all';
          }

          renderTable();
        });
      });

      // ==========================================================================
      // "ADD TO ADOPTION POOL" MODAL LOGIC
      // ==========================================================================
      const addPoolModal = document.getElementById('add-pool-modal');
      const openAddPoolBtn = document.getElementById('open-add-pool-btn');
      const closeAddPoolBtn = document.getElementById('close-add-pool-modal');
      const cancelAddPoolBtn = document.getElementById('cancel-add-pool-modal');
      const petSelect = document.getElementById('select-existing-pet');
      const addPoolForm = document.getElementById('add-pool-form');
      const editPetDetailsModal = document.getElementById('edit-pet-details-modal');
      const editPetDetailsForm = document.getElementById('edit-pet-details-form');
      const closeEditPetDetailsBtn = document.getElementById('close-edit-pet-details-modal');
      const cancelEditPetDetailsBtn = document.getElementById('cancel-edit-pet-details-modal');
      let editPetDetailsId = null;

      // Populate select dropdown with intake registry
      function populatePetSelect() {
        petSelect.innerHTML = '<option value="" disabled selected>Search or select pet...</option>';
        const poolPets = getPoolPets();
        INTAKE_REGISTRY.forEach(item => {
          if (!isEligiblePoolIntake(item, poolPets)) return;
          const opt = document.createElement('option');
          opt.value = item.id;
          opt.textContent = item.name;
          petSelect.appendChild(opt);
        });
      }

      populatePetSelect();

      // Dynamic Preview Auto-fill on Pet Selection
      petSelect.addEventListener('change', () => {
        const selectedId = petSelect.value;
        const pet = INTAKE_REGISTRY.find(p => p.id === selectedId);
        if (!pet) return;

        if (!isEligiblePoolIntake(pet, getPoolPets())) {
          showToast('Animal is no longer eligible', { type: 'error', description: 'Only animals that are pending review and have not been added to the pool can be selected.' });
          populatePetSelect();
          return;
        }

        document.getElementById('modal-preview-empty').style.display = 'none';
        document.getElementById('modal-preview-content').style.display = '';
        document.getElementById('readiness-empty').style.display = 'none';
        document.getElementById('readiness-content').style.display = '';

        document.getElementById('modal-preview-name').textContent = pet.name;
        document.getElementById('modal-preview-meta').textContent = `${pet.species} · ${pet.gender} · ${pet.age}`;
        document.getElementById('modal-preview-source').textContent = pet.rescueSource;
        document.getElementById('modal-preview-date').textContent = pet.rescueDate;
        document.getElementById('modal-preview-brgy').textContent = pet.barangay || 'Paliparan I';
        document.getElementById('modal-preview-loc').textContent = pet.currentLocation || 'DCVS Shelter';
        document.getElementById('modal-preview-photo').src = pet.image;

        if (pet.readiness) {
          document.getElementById('readiness-vaccination').value = pet.readiness.vaccination || 'Not specified';
          document.getElementById('readiness-health').value = pet.readiness.health || 'Not assessed';
          document.getElementById('readiness-spay-neuter').value = pet.readiness.spayNeuter || 'Not specified';
          document.getElementById('readiness-temperament').value = pet.readiness.temperament || 'Not assessed';
        } else {
          document.getElementById('readiness-vaccination').value = 'Not specified';
          document.getElementById('readiness-health').value = 'Not assessed';
          document.getElementById('readiness-spay-neuter').value = 'Not specified';
          document.getElementById('readiness-temperament').value = 'Not assessed';
        }

        // Check if this pet is already in pool as Available or Adopted — lock fields
        const poolPets = getPoolPets();
        const poolPet = poolPets.find(p => p.name === pet.name && p.species === pet.species && p.gender === pet.gender);
        const isLocked = poolPet && (poolPet.adoptionStatus === 'Available' || poolPet.adoptionStatus === 'Adopted' || poolPet.adoptionStatus === 'Reserved');
        statusSelect.disabled = isLocked;
        document.getElementById('pool-ready-date').disabled = isLocked;
        document.getElementById('pool-notes').disabled = isLocked;
        document.getElementById('readiness-vaccination').disabled = isLocked;
        document.getElementById('readiness-health').disabled = isLocked;
        document.getElementById('readiness-spay-neuter').disabled = isLocked;
        document.getElementById('readiness-temperament').disabled = isLocked;
        if (isLocked) {
          statusSelect.value = poolPet.adoptionStatus;
          document.getElementById('pool-ready-date').value = poolPet.readyDate || '';
          document.getElementById('pool-notes').value = poolPet.notes || '';
          if (poolPet.readiness) {
            document.getElementById('readiness-vaccination').value = poolPet.readiness.vaccination || 'Not specified';
            document.getElementById('readiness-health').value = poolPet.readiness.health || 'Not assessed';
            document.getElementById('readiness-spay-neuter').value = poolPet.readiness.spayNeuter || 'Not specified';
            document.getElementById('readiness-temperament').value = poolPet.readiness.temperament || 'Not assessed';
          }
        }
      });

      // Status Helper Messages
      const statusSelect = document.getElementById('pool-status-select');
      const statusCallout = document.getElementById('status-helper-callout');
      const statusText = document.getElementById('status-helper-text');

      const STATUS_HELPERS = {
        'Available': {
          label: 'Available',
          text: 'Cleared and ready to be considered for adoption.'
        },
        'Pending Review': {
          label: 'Pending Review',
          text: 'Under review. Cleared animals will move to the next step.'
        },
        'On Hold': {
          label: 'On Hold',
          text: 'Cannot be published or adopted until the hold is removed.'
        }
      };

      function updateStatusHelper() {
        const val = statusSelect.value;
        const config = STATUS_HELPERS[val] || STATUS_HELPERS['Pending Review'];
        statusText.textContent = config.text;
        statusCallout.style.display = '';
      }

      let statusChanged = false;
      statusSelect.addEventListener('change', () => {
        statusChanged = true;
        updateStatusHelper();
      });

      // Open / Close Modal
      function formatEditDate(value) {
        const raw = String(value || '');
        return /^\d{4}-\d{2}-\d{2}/.test(raw) ? raw.slice(0, 10) : '';
      }

      function openEditPetDetails(pet) {
        if (!pet) return;
        editPetDetailsId = pet.id;
        document.getElementById('edit-pet-select').innerHTML = `<option value="${String(pet.id).replace(/"/g, '&quot;')}">${String(pet.name || 'Selected pet').replace(/[<&>]/g, '')}</option>`;
        document.getElementById('edit-pet-name').value = pet.name || '';
        document.getElementById('edit-pet-species').value = pet.species || 'Dog';
        document.getElementById('edit-pet-breed').value = pet.breed || '';
        document.getElementById('edit-pet-gender').value = pet.gender || 'Unknown';
        document.getElementById('edit-pet-age').value = pet.age || '';
        document.getElementById('edit-pet-rescue-date').value = formatEditDate(pet.fullRescueDate || pet.rescueDate);
        document.getElementById('edit-pet-description').value = pet.notes || '';
        document.getElementById('edit-readiness-vaccination').value = pet.readiness?.vaccination || 'Not specified';
        document.getElementById('edit-readiness-health').value = pet.readiness?.health || 'Not assessed';
        document.getElementById('edit-readiness-spay-neuter').value = pet.readiness?.spayNeuter || 'Not specified';
        document.getElementById('edit-readiness-temperament').value = pet.readiness?.temperament || 'Not assessed';
        document.getElementById('edit-pool-status').value = pet.adoptionStatus || 'Pending Review';
        editPetDetailsModal.classList.add('show');
      }

      function closeEditPetDetails() {
        editPetDetailsId = null;
        editPetDetailsModal.classList.remove('show');
        editPetDetailsForm.reset();
      }

      closeEditPetDetailsBtn.addEventListener('click', closeEditPetDetails);
      cancelEditPetDetailsBtn.addEventListener('click', closeEditPetDetails);
      editPetDetailsModal.addEventListener('click', (e) => {
        if (e.target === editPetDetailsModal) closeEditPetDetails();
      });

      editPetDetailsForm.addEventListener('submit', (e) => {
        e.preventDefault();
        if (!editPetDetailsId) return;

        const pets = getPoolPets();
        const target = pets.find(p => p.id === editPetDetailsId);
        if (!target) return;

        const oldIdentity = { name: target.name, species: target.species, gender: target.gender };
        target.name = document.getElementById('edit-pet-name').value.trim();
        target.species = document.getElementById('edit-pet-species').value;
        target.breed = document.getElementById('edit-pet-breed').value.trim();
        target.gender = document.getElementById('edit-pet-gender').value;
        target.age = document.getElementById('edit-pet-age').value.trim();
        target.fullRescueDate = document.getElementById('edit-pet-rescue-date').value;
        target.rescueDate = target.fullRescueDate;
        target.notes = document.getElementById('edit-pet-description').value.trim();
        target.adoptionStatus = document.getElementById('edit-pool-status').value;
        target.readiness = {
          vaccination: document.getElementById('edit-readiness-vaccination').value,
          health: document.getElementById('edit-readiness-health').value,
          spayNeuter: document.getElementById('edit-readiness-spay-neuter').value,
          temperament: document.getElementById('edit-readiness-temperament').value
        };

        const intake = INTAKE_REGISTRY.find(p => p.id === target.intakeId || (
          p.name === oldIdentity.name && p.species === oldIdentity.species && p.gender === oldIdentity.gender
        ));
        if (intake) {
          intake.name = target.name;
          intake.species = target.species;
          intake.breed = target.breed;
          intake.gender = target.gender;
          intake.age = target.age;
          intake.rescueDate = target.fullRescueDate;
          intake.shortRescueDate = target.fullRescueDate;
          intake.notes = target.notes;
        }

        savePoolPets(pets);
        localStorage.setItem(INTAKE_KEY, JSON.stringify(INTAKE_REGISTRY));
        syncToDB(target);
        refreshCounts();
        renderTable();
        closeEditPetDetails();
        showToast('Pet Details Updated', { type: 'success', description: `${target.name}'s information has been saved.` });
      });

      function openAddModal(editPet) {
        if (!editPet) populatePetSelect();
        addPoolModal.classList.add('show');
        statusChanged = false;
        statusCallout.style.display = 'none';

        // Reset lock state
        statusSelect.disabled = false;
        document.getElementById('pool-ready-date').disabled = false;
        document.getElementById('pool-notes').disabled = false;

        if (editPet) {
          // Edit mode — pre-fill with current pet data
          const poolPets = getPoolPets();
          const poolPet = poolPets.find(p => p.id === editPet.id) || editPet;

          // Find matching intake option and select it
          const intakeIdx = INTAKE_REGISTRY.findIndex(p => p.name === poolPet.name && p.species === poolPet.species && p.gender === poolPet.gender);
          if (intakeIdx >= 0) {
            petSelect.selectedIndex = intakeIdx + 1;
            petSelect.dispatchEvent(new Event('change'));
          }

          statusSelect.value = poolPet.adoptionStatus;
          document.getElementById('pool-ready-date').value = poolPet.readyDate || '';
          document.getElementById('pool-notes').value = poolPet.notes || '';

          // Pre-fill readiness dropdowns
          const r = poolPet.readiness || {};
          document.getElementById('readiness-vaccination').value = r.vaccination || 'Not specified';
          document.getElementById('readiness-health').value = r.health || 'Not assessed';
          document.getElementById('readiness-spay-neuter').value = r.spayNeuter || 'Not specified';
          document.getElementById('readiness-temperament').value = r.temperament || 'Not assessed';

          // Lock if Available or Adopted
          const isLocked = poolPet.adoptionStatus === 'Available' || poolPet.adoptionStatus === 'Adopted' || poolPet.adoptionStatus === 'Reserved';
          statusSelect.disabled = isLocked;
          document.getElementById('pool-ready-date').disabled = isLocked;
          document.getElementById('pool-notes').disabled = isLocked;
          document.getElementById('readiness-vaccination').disabled = isLocked;
          document.getElementById('readiness-health').disabled = isLocked;
          document.getElementById('readiness-spay-neuter').disabled = isLocked;
          document.getElementById('readiness-temperament').disabled = isLocked;
        } else {
          // Add mode — reset
          petSelect.selectedIndex = 0;
          statusSelect.value = 'Pending Review';
          document.getElementById('pool-ready-date').value = '';
          document.getElementById('pool-notes').value = '';
          document.getElementById('readiness-vaccination').value = 'Not specified';
          document.getElementById('readiness-health').value = 'Not assessed';
          document.getElementById('readiness-spay-neuter').value = 'Not specified';
          document.getElementById('readiness-temperament').value = 'Not assessed';
          document.getElementById('modal-preview-empty').style.display = '';
          document.getElementById('modal-preview-content').style.display = 'none';
          document.getElementById('readiness-empty').style.display = '';
          document.getElementById('readiness-content').style.display = 'none';
        }
      }

      function closeAddModal() {
        addPoolModal.classList.remove('show');
        statusChanged = false;
        statusCallout.style.display = 'none';
        statusSelect.disabled = false;
        document.getElementById('pool-ready-date').disabled = false;
        document.getElementById('pool-notes').disabled = false;
        document.getElementById('readiness-vaccination').disabled = false;
        document.getElementById('readiness-health').disabled = false;
        document.getElementById('readiness-spay-neuter').disabled = false;
        document.getElementById('readiness-temperament').disabled = false;
        statusSelect.value = 'Pending Review';
        document.getElementById('pool-ready-date').value = '';
        document.getElementById('pool-notes').value = '';
        document.getElementById('readiness-vaccination').value = 'Not specified';
        document.getElementById('readiness-health').value = 'Not assessed';
        document.getElementById('readiness-spay-neuter').value = 'Not specified';
        document.getElementById('readiness-temperament').value = 'Not assessed';
        document.getElementById('modal-preview-empty').style.display = '';
        document.getElementById('modal-preview-content').style.display = 'none';
        document.getElementById('readiness-empty').style.display = '';
        document.getElementById('readiness-content').style.display = 'none';
      }

      openAddPoolBtn.addEventListener('click', openAddModal);
      closeAddPoolBtn.addEventListener('click', closeAddModal);
      cancelAddPoolBtn.addEventListener('click', closeAddModal);
      addPoolModal.addEventListener('click', (e) => {
        if (e.target === addPoolModal) closeAddModal();
      });

      // Handle Add to Pool Form Submit
      addPoolForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const selectedId = petSelect.value;
        const pet = INTAKE_REGISTRY.find(p => p.id === selectedId);
        if (!pet) return;
        const poolPets = getPoolPets();
        if (!isEligiblePoolIntake(pet, poolPets)) {
          showToast('Animal is no longer eligible', { type: 'error', description: 'Only animals that are pending review and have not been added to the pool can be selected.' });
          populatePetSelect();
          return;
        }

        const availabilityStatus = statusSelect.value;
        const readyDate = document.getElementById('pool-ready-date').value;
        const notes = document.getElementById('pool-notes').value;

        let targetStatus = 'Pending Review';
        if (availabilityStatus === 'Available') targetStatus = 'Available';
        else if (availabilityStatus === 'On Hold') targetStatus = 'On Hold';

        // If Available + future date set, keep as Pending Review until that date
        let scheduledDate = '';
        if (targetStatus === 'Available' && readyDate) {
          const rDate = new Date(readyDate + 'T00:00:00');
          const today = new Date();
          today.setHours(0,0,0,0);
          if (rDate > today) {
            scheduledDate = readyDate;
            targetStatus = 'Pending Review';
          }
        }

        // New entry — exact rule: Listing Status: Not Listed
        const newPoolEntry = {
          id: 'pet-' + Date.now(),
          intakeId: pet.id,
          name: pet.name,
          species: pet.species,
          breed: pet.breed,
          gender: pet.gender,
          age: pet.age,
          rescueSource: pet.rescueSource,
          rescueDate: pet.shortRescueDate,
          fullRescueDate: pet.rescueDate,
          adoptionStatus: targetStatus,
          listingStatus: 'Not Listed',
          image: pet.image,
          readyDate: readyDate || '',
          scheduledDate: scheduledDate,
          notes: notes || pet.notes,
          readiness: {
            vaccination: document.getElementById('readiness-vaccination').value,
            health: document.getElementById('readiness-health').value,
            spayNeuter: document.getElementById('readiness-spay-neuter').value,
            temperament: document.getElementById('readiness-temperament').value
          }
        };

        poolPets.unshift(newPoolEntry);
        savePoolPets(poolPets);
        markPoolIntakeAsAdded(newPoolEntry);

        syncToDB(newPoolEntry);

        refreshCounts();
        renderTable();
        populatePetSelect();
        closeAddModal();
        showToast('Added to Pool', { type: 'success', description: `${pet.name} added to adoption pool with status: ${targetStatus}.` });
      });

      // ==========================================================================
      // PET DETAILS DRAWER & OPERATIONAL WORKFLOW CONTROLS
      // ==========================================================================
      const petDrawer = document.getElementById('pet-drawer');
      const drawerBackdrop = document.getElementById('drawer-backdrop');
      const closeDrawerBtn = document.getElementById('close-pet-drawer');
      const drawerContent = document.getElementById('drawer-body-content');
      const drawerFooter = document.getElementById('drawer-footer-actions');

      let currentDrawerPet = null;

      function openPetDrawer(petId) {
        const pets = getPoolPets();
        const pet = pets.find(p => p.id === petId);
        if (!pet) return;
        currentDrawerPet = pet;

        // Render Drawer Content
        const isAdopted = pet.adoptionStatus === 'Adopted';
        const isReserved = pet.adoptionStatus === 'Reserved';
        const badgeClass = isReserved ? 'reserved' : (pet.adoptionStatus === 'Available' ? 'available' : (pet.adoptionStatus === 'Pending Review' ? 'pending' : 'adopted'));
        const readiness = pet.readiness || {};
        const isReadyForAdoption = !isAdopted && !isReserved &&
          readiness.vaccination && readiness.vaccination !== 'Not specified' &&
          readiness.health && readiness.health !== 'Not assessed' &&
          readiness.spayNeuter && readiness.spayNeuter !== 'Not specified' &&
          readiness.temperament && readiness.temperament !== 'Not assessed';
        const readinessLabel = isAdopted ? '✓ Adopted' : (isReserved ? '● Reserved' : (isReadyForAdoption ? '✓ Ready for Adoption' : '● Pending Review'));
        const readinessColor = isAdopted ? '#1e40af' : (isReserved ? '#92400e' : (isReadyForAdoption ? '#16a34a' : '#ca8a04'));
        const custodianBarangay = String(pet.adopterBarangay || '').replace(/\s+([IV]{1,3})$/i, (_, numeral) => {
          const romanNumerals = { I: '1', II: '2', III: '3', IV: '4' };
          return ' ' + (romanNumerals[numeral.toUpperCase()] || numeral);
        });

        const hasReachedAvailability = ['Available', 'Reserved', 'Adopted'].includes(pet.adoptionStatus) || pet.listingStatus === 'Published';
        const hasReachedPublished = pet.listingStatus === 'Published' || isReserved || isAdopted;
        const stages = [
          { key: 'Rescued', done: true },
          { key: 'Pending Review', done: pet.adoptionStatus !== 'Pending Review', active: pet.adoptionStatus === 'Pending Review' },
          { key: 'Available', done: hasReachedAvailability, active: pet.adoptionStatus === 'Available' && pet.listingStatus !== 'Published' },
          { key: 'Published', done: hasReachedPublished, active: pet.listingStatus === 'Published' && !isReserved && !isAdopted },
          { key: 'Reserved', done: isReserved || isAdopted },
          { key: 'Adopted', done: pet.adoptionStatus === 'Adopted', active: pet.adoptionStatus === 'Adopted' }
        ];

        let progressHtml = '';
        stages.forEach((s, i) => {
          const dotClass = s.done ? 'done' : (s.active ? 'active' : '');
          const lineClass = s.done ? 'done' : '';
          progressHtml += `<div class="progress-step"><div class="progress-dot ${dotClass}">${s.done ? '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>' : ''}</div><span class="progress-label ${s.done || s.active ? 'active-label' : ''}">${s.key}</span></div>`;
          if (i < stages.length - 1) {
            progressHtml += `<div class="progress-line ${lineClass}"></div>`;
          }
        });

        drawerContent.innerHTML = `
          <div class="drawer-hero-profile">
            <img src="${pet.image}" alt="${pet.name}" class="drawer-pet-img">
            <div class="drawer-pet-details">
              <span class="drawer-pet-role">For pet adoption</span>
              <h2 class="drawer-pet-name">${pet.name}</h2>
            </div>
            <span class="drawer-status-badge ${badgeClass}">${pet.adoptionStatus === 'Adopted' ? 'Adopted' : (pet.listingStatus === 'Published' ? 'Published' : pet.adoptionStatus)}</span>
          </div>

          <div class="adoption-progress">${progressHtml}</div>

          <div class="info-card-head"><h3>Pet information</h3>${!isAdopted && !isReserved ? '<button type="button" class="pool-edit-icon" id="drawer-edit-btn" aria-label="Edit pet" title="Edit pet"><img src="assets/images/edit%20icon.png" alt=""></button>' : ''}</div>
          <div class="drawer-info-grid">
            <div class="drawer-info-item"><span class="drawer-info-label">Rescue Source</span><span class="drawer-info-val">${pet.rescueSource}</span></div>
            <div class="drawer-info-item"><span class="drawer-info-label">Rescue Date</span><span class="drawer-info-val">${pet.fullRescueDate || pet.rescueDate}</span></div>
            <div class="drawer-info-item"><span class="drawer-info-label">Species</span><span class="drawer-info-val">${pet.species}</span></div>
            <div class="drawer-info-item"><span class="drawer-info-label">Breed</span><span class="drawer-info-val">${pet.breed}</span></div>
            <div class="drawer-info-item"><span class="drawer-info-label">Sex</span><span class="drawer-info-val">${pet.gender}</span></div>
            <div class="drawer-info-item"><span class="drawer-info-label">Age</span><span class="drawer-info-val">${pet.age}</span></div>
            <div class="drawer-info-item"><span class="drawer-info-label">Availability</span><span class="drawer-info-val">${pet.scheduledDate ? 'Will be available on ' + pet.scheduledDate : (pet.readyDate || '—')}</span></div>
            <div class="drawer-info-item"><span class="drawer-info-label">Adoption Status</span><span class="drawer-info-val">${pet.adoptionStatus}</span></div>
            ${(pet.adoptionApplicationStatus === 'approved' || pet.adoptionApplicationStatus === 'expired') ? `
              <div class="drawer-info-item"><span class="drawer-info-label">Scheduled Pickup</span><span class="drawer-info-val" id="drawer-scheduled-pickup"></span></div>
              <div class="drawer-info-item"><span class="drawer-info-label">Pickup Period</span><span class="drawer-info-val" id="drawer-pickup-period"></span></div>
            ` : ''}
            ${isAdopted ? `<div class="drawer-info-item"><span class="drawer-info-label">Confirmed/Adopted Date</span><span class="drawer-info-val" id="drawer-adopted-date"></span></div>` : ''}
          </div>
          <div class="drawer-section-title">Adoption readiness</div>
          <table class="readiness-table">
            <thead><tr><th>Check</th><th>Status</th></tr></thead>
            <tbody>
              <tr><td>Vaccination Status</td><td>${pet.readiness?.vaccination || 'Not specified'}${pet.readiness?.vaccination && pet.readiness.vaccination !== 'Not specified' ? ' ✓' : ''}</td></tr>
              <tr><td>Health / Medical Status</td><td>${pet.readiness?.health || 'Not assessed'}${pet.readiness?.health && pet.readiness.health !== 'Not assessed' ? ' ✓' : ''}</td></tr>
              <tr><td>Spay / Neuter</td><td>${pet.readiness?.spayNeuter || 'Not specified'}${pet.readiness?.spayNeuter && pet.readiness.spayNeuter !== 'Not specified' ? ' ✓' : ''}</td></tr>
              <tr><td>Temperament</td><td>${pet.readiness?.temperament || 'Not assessed'}${pet.readiness?.temperament && pet.readiness.temperament !== 'Not assessed' ? ' ✓' : ''}</td></tr>
            </tbody>
          </table>
          ${!isAdopted ? `<div style="margin-top: 8px; font-size: 13px; font-weight: 600; color: #18181b;">
            Readiness<br>
            <span style="font-weight: 500; font-size: 13px; color: ${readinessColor};">${readinessLabel}</span>
          </div>` : ''}
          <div class="drawer-section-title">Current Custodian</div>
          <div class="drawer-desc-box" id="drawer-custodian-content"></div>
        `;

        const scheduledPickup = document.getElementById('drawer-scheduled-pickup');
        if (scheduledPickup) {
          const pickupDate = pet.approvedPickupAt ? new Date(String(pet.approvedPickupAt).replace(' ', 'T')) : null;
          scheduledPickup.textContent = pickupDate && !Number.isNaN(pickupDate.getTime())
            ? pickupDate.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
            : '—';
        }
        const pickupPeriod = document.getElementById('drawer-pickup-period');
        if (pickupPeriod) {
          pickupPeriod.textContent = window.adoptionPickupPeriodLabel(pet.approvedPickupAt, pet.adoptionApplicationStatus);
        }
        const adoptedDate = document.getElementById('drawer-adopted-date');
        if (adoptedDate) adoptedDate.textContent = pet.adoptedDate || '—';

        const custodianContent = document.getElementById('drawer-custodian-content');
        if (pet.adoptionStatus === 'Adopted') {
          const adopterName = document.createElement('div');
          adopterName.textContent = pet.adopterName || 'Adopter details were not recorded for this adoption.';
          custodianContent.appendChild(adopterName);
          if (custodianBarangay) {
            const adopterBarangay = document.createElement('div');
            adopterBarangay.textContent = custodianBarangay;
            custodianContent.appendChild(adopterBarangay);
          }
        } else {
          custodianContent.textContent = 'No custodian on file for this listing yet.';
        }

        // Render Drawer Footer Controls — context-sensitive per lifecycle state
        const isPending = pet.adoptionStatus === 'Pending Review';
        const isAvailable = pet.adoptionStatus === 'Available';
        const isPublished = pet.listingStatus === 'Published';

        let footerHtml = '';
        if (isAdopted) {
          footerHtml = `<button type="button" class="sd-btn sd-btn-outline" id="drawer-archive-btn">Archive</button><button type="button" class="sd-btn sd-btn-outline" onclick="closePetDrawer()">Close</button>`;
        } else if (isReserved && pet.adoptionApplicationStatus === 'approved' && Number(pet.adoptionApplicationId) > 0) {
          footerHtml = `<button type="button" class="sd-btn sd-btn-default" id="drawer-finalize-adoption-btn">Mark Adopted</button><button type="button" class="sd-btn sd-btn-outline" onclick="closePetDrawer()">Close</button>`;
        } else if (isPending) {
          footerHtml = `
            <button class="sd-btn sd-btn-outline" id="drawer-archive-btn">Archive</button>
            <button class="sd-btn sd-btn-default" id="drawer-set-available-btn">Set as Available</button>
          `;
        } else if (isAvailable && !isPublished) {
          footerHtml = `
            <button class="sd-btn sd-btn-outline" id="drawer-archive-btn">Archive</button>
            <button class="sd-btn sd-btn-default" id="drawer-toggle-publish-btn">Publish</button>
          `;
        } else if (isAvailable && isPublished) {
          footerHtml = `
            <button class="sd-btn sd-btn-outline" id="drawer-archive-btn">Archive</button>
            <button class="sd-btn sd-btn-outline" id="drawer-toggle-publish-btn">Unpublish</button>
          `;
        }

        drawerFooter.innerHTML = footerHtml;

        document.getElementById('drawer-edit-btn')?.addEventListener('click', () => openEditPetDetails(pet));
        document.getElementById('drawer-finalize-adoption-btn')?.addEventListener('click', () => showConfirmDialog({
          title: 'Finalize ' + pet.name + '\'s adoption?',
          desc: 'Confirm that the resident has completed the DCVS visit and picked up ' + pet.name + '. This will register the pet to the resident and mark the application as adopted.',
          confirmLabel: 'Mark Adopted',
          onConfirm: () => finalizeApprovedAdoption(pet)
        }));
        document.getElementById('drawer-archive-btn')?.addEventListener('click', () => showConfirmDialog({
          title: 'Archive Animal?',
          desc: 'Are you sure you want to archive this animal? Archived animals will no longer appear in the active adoption pool.',
          confirmLabel: 'Archive',
          variant: 'danger',
          onConfirm: () => {
            const pets = getPoolPets();
            const idx = pets.findIndex(p => p.id === pet.id);
            if (idx < 0) return;
            pets[idx].listingStatus = 'Archived';
            markPoolIntakeAsAdded(pets[idx]);
            syncToDB(pets[idx]);
            pets.splice(idx, 1);
            savePoolPets(pets);
            refreshCounts();
            renderTable();
            closePetDrawer();
            showToast('Animal Archived', { type: 'success', description: pet.name + ' has been removed from the active pool.' });
          }
        }));

        if (isPending) {
          document.getElementById('drawer-set-available-btn')?.addEventListener('click', () => showConfirmDialog({
            title: 'Set ' + pet.name + ' as Available?',
            desc: 'Are you sure you want to set this animal as available for adoption? This will make the animal eligible to be listed for adoption.',
            confirmLabel: 'Set as Available',
            onConfirm: () => setAsAvailable(pet)
          }));
        }
        if (isAvailable) {
          document.getElementById('drawer-toggle-publish-btn')?.addEventListener('click', () => showConfirmDialog({
            title: isPublished ? 'Unpublish Animal?' : 'Publish Animal?',
            desc: isPublished
              ? 'Are you sure you want to unpublish ' + pet.name + '? The animal will be hidden from the public adoption page.'
              : 'Are you sure you want to publish ' + pet.name + ' on the public adoption page?',
            confirmLabel: isPublished ? 'Unpublish' : 'Publish',
            onConfirm: () => toggleListingStatus(pet)
          }));
        }

        petDrawer.classList.add('show');
        drawerBackdrop.classList.add('show');
      }

      function closePetDrawer() {
        petDrawer.classList.remove('show');
        drawerBackdrop.classList.remove('show');
        currentDrawerPet = null;
      }

      function finalizeApprovedAdoption(pet) {
        const formData = new FormData();
        formData.set('csrf_token', window.adoptionWorkflowCsrf || '');
        formData.set('staff_action', 'finalize_adoption');
        formData.set('application_id', String(pet.adoptionApplicationId));
        fetch('adoption-workflow-handler.php', { method: 'POST', body: formData, credentials: 'same-origin' })
          .then(response => response.json().then(body => ({ ok: response.ok, body })))
          .then(result => {
            if (!result.ok || !result.body.success) {
              showToast('Could Not Finalize', { type: 'error', description: result.body.message || 'The adoption could not be finalized.' });
              return;
            }
            showToast('Adoption Finalized', { type: 'success', description: pet.name + ' is now marked as adopted.' });
            window.setTimeout(() => window.location.reload(), 1800);
          })
          .catch(() => showToast('Could Not Finalize', { type: 'error', description: 'The adoption request could not be sent. Check your connection and try again.' }));
      }

      closeDrawerBtn.addEventListener('click', closePetDrawer);
      drawerBackdrop.addEventListener('click', closePetDrawer);

      // ==========================================================================
      // SHARED CONFIRMATION DIALOG
      // ==========================================================================
      let confirmDialogAction = null;
      let confirmDialogOnHide = null;

      function showConfirmDialog(opts) {
        document.getElementById('confirm-dialog-title').textContent = opts.title || '';
        document.getElementById('confirm-dialog-desc').textContent = opts.desc || '';
        const confirmBtn = document.getElementById('confirm-dialog-confirm');
        confirmBtn.textContent = opts.confirmLabel || 'Confirm';
        confirmBtn.className = 'btn-submit' + (opts.variant === 'danger' ? '' : '');
        if (opts.variant === 'danger') {
          confirmBtn.style.background = '#dc2626';
          confirmBtn.style.borderColor = '#dc2626';
        } else {
          confirmBtn.style.background = '#18181b';
          confirmBtn.style.borderColor = '#18181b';
        }
        confirmDialogAction = opts.onConfirm || null;
        confirmDialogOnHide = opts.onHide || null;
        document.getElementById('confirm-dialog').classList.add('show');
      }

      function hideConfirmDialog() {
        document.getElementById('confirm-dialog').classList.remove('show');
        confirmDialogAction = null;
        if (confirmDialogOnHide) {
          var onHideCb = confirmDialogOnHide;
          confirmDialogOnHide = null;
          onHideCb();
        }
      }

      document.getElementById('confirm-dialog-confirm').addEventListener('click', () => {
        if (confirmDialogAction) confirmDialogAction();
        hideConfirmDialog();
      });
      document.getElementById('confirm-dialog-close').addEventListener('click', hideConfirmDialog);
      document.getElementById('confirm-dialog-cancel').addEventListener('click', hideConfirmDialog);
      document.getElementById('confirm-dialog').addEventListener('click', (e) => {
        if (e.target.id === 'confirm-dialog') hideConfirmDialog();
      });

      // Pending Review → Available (ready for adoption, not yet published)
      function setAsAvailable(pet) {
        const pets = getPoolPets();
        const target = pets.find(p => p.id === pet.id);
        if (!target) return;

        if (target.adoptionStatus !== 'Pending Review') {
          showToast('Invalid Status', { type: 'error', description: `${target.name} is not in Pending Review status.` });
          return;
        }

        const r = target.readiness || {};
        const incomplete = [];
        if (!r.vaccination || r.vaccination === 'Not specified') incomplete.push('Vaccination');
        if (!r.health || r.health === 'Not assessed') incomplete.push('Health / Medical');
        if (!r.spayNeuter || r.spayNeuter === 'Not specified') incomplete.push('Spay / Neuter');
        if (!r.temperament || r.temperament === 'Not assessed') incomplete.push('Temperament');
        if (incomplete.length > 0) {
          showToast('Incomplete Readiness', { type: 'error', description: `Complete readiness assessment first: ${incomplete.join(', ')}.` });
          return;
        }

        target.adoptionStatus = 'Available';
        target.listingStatus = 'Not Listed';

        savePoolPets(pets);
        syncToDB(target);
        refreshCounts();
        renderTable();
        openPetDrawer(target.id);

        showToast('Available', { type: 'success', description: `${target.name} is now Available for adoption. You can publish when ready.` });
      }

      // Available ↔ Published toggle
      function toggleListingStatus(pet) {
        const pets = getPoolPets();
        const target = pets.find(p => p.id === pet.id);
        if (!target) return;

        if (target.adoptionStatus !== 'Available') {
          showToast('Cannot Publish', { type: 'error', description: `${target.name} must be set as Available before publishing.` });
          return;
        }

        if (target.listingStatus === 'Published') {
          target.listingStatus = 'Not Listed';
          showToast('Unpublished', { type: 'info', description: `${target.name} unpublished. Hidden from public adoption feed.` });
        } else {
          target.listingStatus = 'Published';
          showToast('Published', { type: 'success', description: `${target.name} is now Published on the adoption portal.` });
        }

        savePoolPets(pets);
        syncToDB(target);
        refreshCounts();
        renderTable();
        openPetDrawer(target.id);
      }

      // Record Rescue Modal in Adoption Pool
      const rescueModal = document.getElementById('record-rescue-modal');
      const closeRescueModalBtn = document.getElementById('close-rescue-modal');
      const cancelRescueModalBtn = document.getElementById('cancel-rescue-modal');
      const rescueForm = document.getElementById('rescue-form');

      // Record Rescue Dropdown
      const rescueDropdownWrap = document.getElementById('rescue-dropdown-wrap');
      const rescueDropdownMenu = document.getElementById('rescue-dropdown-menu');
      const openRescueHeaderBtn = document.getElementById('header-record-rescue-btn');

      openRescueHeaderBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        rescueDropdownMenu.classList.toggle('show');
      });

      document.addEventListener('click', (e) => {
        if (!rescueDropdownWrap.contains(e.target)) {
          rescueDropdownMenu.classList.remove('show');
        }
      });

      document.querySelectorAll('.rescue-dropdown-item').forEach(item => {
        item.addEventListener('click', () => {
          rescueDropdownMenu.classList.remove('show');
          const type = item.dataset.type;
          if (type === 'single') {
            rescueModal.classList.add('show');
          } else {
            multiRescueModal.classList.add('show');
          }
        });
      });

      function closeRescueModal() {
        rescueModal.classList.remove('show');
        rescueForm.reset();
        resetRescuePhoto();
      }

      closeRescueModalBtn.addEventListener('click', closeRescueModal);
      cancelRescueModalBtn.addEventListener('click', closeRescueModal);
      rescueModal.addEventListener('click', (e) => {
        if (e.target === rescueModal) closeRescueModal();
      });

      // Photo upload for rescue form
      let rescuePhotoData = null;
      const rescuePhotoUpload = document.getElementById('rescue-photo-upload');
      const rescuePhotoInput = document.getElementById('rescue-photo-input');
      const rescuePhotoPlaceholder = document.getElementById('rescue-photo-placeholder');
      const rescuePhotoPreview = document.getElementById('rescue-photo-preview');
      const rescuePhotoImg = document.getElementById('rescue-photo-img');
      const rescuePhotoRemove = document.getElementById('rescue-photo-remove');

      rescuePhotoUpload.addEventListener('click', (e) => {
        if (e.target.closest('.rescue-photo-remove')) return;
        rescuePhotoInput.click();
      });

      rescuePhotoInput.addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (!file) return;
        if (!file.type.startsWith('image/')) return;
        const reader = new FileReader();
        reader.onload = (ev) => {
          rescuePhotoData = ev.target.result;
          rescuePhotoImg.src = rescuePhotoData;
          rescuePhotoPlaceholder.style.display = 'none';
          rescuePhotoPreview.style.display = 'block';
        };
        reader.readAsDataURL(file);
      });

      rescuePhotoRemove.addEventListener('click', (e) => {
        e.stopPropagation();
        resetRescuePhoto();
      });

      function resetRescuePhoto() {
        rescuePhotoData = null;
        rescuePhotoInput.value = '';
        rescuePhotoImg.src = '';
        rescuePhotoPlaceholder.style.display = '';
        rescuePhotoPreview.style.display = 'none';
      }

      // Single rescue form handler is in the batch section below

      // ==========================================================================
      // RESCUE BATCH DATA
      // ==========================================================================

      function getBatches() {
        try {
          const stored = localStorage.getItem(BATCH_KEY_V2);
          if (stored) return JSON.parse(stored);
        } catch(e) {}
        return [];
      }

      function saveBatches(batches) {
        localStorage.setItem(BATCH_KEY_V2, JSON.stringify(batches));
      }

      function normalizePoolRescueDate(value) {
        const raw = String(value || '').trim();
        const isoDate = raw.match(/^(\d{4}-\d{2}-\d{2})/);
        if (isoDate) return isoDate[1];
        const shortDate = raw.match(/^([A-Za-z]{3,9})\s+(\d{1,2})(?:,?\s+(\d{4}))?$/);
        if (!shortDate) return '';
        const month = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'].indexOf(shortDate[1].slice(0, 3).toLowerCase()) + 1;
        if (month < 1) return '';
        const year = Number(shortDate[3] || new Date().getFullYear());
        return year + '-' + String(month).padStart(2, '0') + '-' + String(Number(shortDate[2])).padStart(2, '0');
      }

      function backfillLegacyRescueIntakes() {
        const pets = getPoolPets();
        const batches = getBatches();
        const batchById = new Map(batches.map(batch => [String(batch.id), batch]));
        const petsToSync = new Set();
        let petsChanged = false;
        let batchesChanged = false;
        let work = Promise.resolve();

        batches.forEach(batch => {
          if (Number(batch.rescueIntakeId) > 0) return;
          const date = normalizePoolRescueDate(batch.rescueDate);
          if (!POOL_RESCUE_SOURCES[batch.rescueSource] || !date || Number(batch.count) <= 0) return;
          work = work.then(() => recordPoolRescueIntake(batch.rescueSource, date, batch.barangay, Number(batch.count), batch.notes || '')
            .then(intakeId => {
              batch.rescueIntakeId = intakeId;
              batchesChanged = true;
              pets.forEach(pet => {
                if (String(pet.batchId || '') === String(batch.id) && Number(pet.rescueIntakeId) <= 0) {
                  pet.rescueIntakeId = intakeId;
                  petsChanged = true;
                  petsToSync.add(pet);
                }
              });
            }).catch(() => {}));
        });

        return work.then(() => {
          batches.forEach(batch => {
            if (Number(batch.rescueIntakeId) <= 0) return;
            pets.forEach(pet => {
              if (String(pet.batchId || '') === String(batch.id) && Number(pet.rescueIntakeId) <= 0) {
                pet.rescueIntakeId = Number(batch.rescueIntakeId);
                petsChanged = true;
                petsToSync.add(pet);
              }
            });
          });

          const legacyPets = pets.filter(pet => {
            if (Number(pet.rescueIntakeId) > 0 || (pet.batchId && batchById.has(String(pet.batchId)))) return false;
            return !!POOL_RESCUE_SOURCES[pet.rescueSource] && !!normalizePoolRescueDate(pet.fullRescueDate || pet.rescueDate);
          });
          return legacyPets.reduce((promise, pet) => promise.then(() => {
            const date = normalizePoolRescueDate(pet.fullRescueDate || pet.rescueDate);
            return recordPoolRescueIntake(pet.rescueSource, date, pet.barangay || '', 1, pet.notes || '')
              .then(intakeId => {
                pet.rescueIntakeId = intakeId;
                petsChanged = true;
                petsToSync.add(pet);
              }).catch(() => {});
          }), Promise.resolve());
        }).then(() => {
          if (batchesChanged) saveBatches(batches);
          if (petsChanged) savePoolPets(pets);
          petsToSync.forEach(syncToDB);
        });
      }

      function generateBatchId() {
        const now = new Date();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        const yyyy = now.getFullYear();
        const seq = String(getBatches().length + 1).padStart(2, '0');
        return `RB-${yyyy}-${mm}${dd}-${seq}`;
      }

      function formatBatchDate(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr + 'T00:00:00');
        return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
      }

      function formatBatchDateShort(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr + 'T00:00:00');
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return `${months[d.getMonth()]} ${String(d.getDate()).padStart(2,'0')}, ${d.getFullYear()}`;
      }

      function batchProgressPct(batch) {
        return batch.count > 0 ? Math.round((batch.completedCount / batch.count) * 100) : 0;
      }

      // Render batch cards in the separate section
      function renderBatches() {
        const allBatches = getBatches();
        let batches = allBatches.filter(b => b.completedCount < b.count);

        const bSearch = (document.getElementById('batch-search-input')?.value || '').toLowerCase().trim();
        const bSource = document.getElementById('batch-filter-source')?.value || 'all';
        const bStatus = document.getElementById('batch-filter-status')?.value || 'all';

        batches = batches.filter(b => {
          const matchSearch = !bSearch || b.id.toLowerCase().includes(bSearch) || b.rescueSource.toLowerCase().includes(bSearch) || b.barangay.toLowerCase().includes(bSearch);
          const matchSource = bSource === 'all' || b.rescueSource === bSource;
          let matchStatus = true;
          if (bStatus === 'completed') matchStatus = b.completedCount >= b.count;
          else if (bStatus === 'incomplete') matchStatus = b.completedCount < b.count;
          return matchSearch && matchSource && matchStatus;
        });

        const grid = document.getElementById('batch-cards-grid');
        const emptyEl = document.getElementById('batch-section-empty');

        if (batches.length === 0) {
          grid.style.display = 'none';
          if (emptyEl) emptyEl.style.display = '';
          return;
        }

        grid.style.display = '';
        if (emptyEl) emptyEl.style.display = 'none';
        grid.innerHTML = '';

        // Migrate old format if needed
        let migrated = false;
        batches.forEach(batch => {
          if (!batch.slots && batch.animals) {
            batch.slots = batch.animals.map((a, i) => ({
              index: i + 1,
              status: a.status === 'completed' ? 'completed' : 'awaiting',
              animalName: a.name !== `Animal ${i+1}` ? a.name : null,
              poolPetId: a.poolPetId || null
            }));
            batch.completedCount = batch.slots.filter(s => s.status === 'completed').length;
            migrated = true;
          }
          if (!batch.slots) {
            batch.slots = [];
            for (let i = 1; i <= (batch.count || 0); i++) {
              batch.slots.push({ index: i, status: 'awaiting', animalName: null, poolPetId: null });
            }
            migrated = true;
          }
        });
        if (migrated) saveBatches(batches);

        batches.forEach(batch => {
          const pct = batchProgressPct(batch);
          const card = document.createElement('div');
          card.className = 'batch-card';
          card.addEventListener('click', () => openBatchDossier(batch.id));

          card.innerHTML = `
            <div class="batch-card-id">#${batch.id}</div>
            <div class="batch-card-count">${batch.count} Animals Rescued</div>
            <div class="batch-card-tags">
              <span class="batch-card-tag">${batch.barangay}</span>
              <span class="batch-card-tag">${formatBatchDateShort(batch.rescueDate)}</span>
              <span class="batch-card-tag">${batch.rescueSource}</span>
              <span class="batch-card-tag">${batch.currentLocation}</span>
            </div>
            <div class="batch-card-progress-bar">
              <div class="batch-card-progress-fill ${pct === 100 ? 'full' : pct > 0 ? 'mid' : 'low'}" style="width:${pct}%;"></div>
            </div>
            <div class="batch-card-progress-text">
              <span>Details: <strong>${batch.completedCount} / ${batch.count}</strong> completed</span>
              <span class="batch-card-progress-pct">${pct}%</span>
            </div>
          `;

          grid.appendChild(card);
        });
      }

      // Multi-Rescue Modal
      const multiRescueModal = document.getElementById('multi-rescue-modal');
      const closeMultiRescueBtn = document.getElementById('close-multi-rescue-modal');
      const cancelMultiRescueBtn = document.getElementById('cancel-multi-rescue-modal');
      const multiRescueForm = document.getElementById('multi-rescue-form');
      const multiRescueSubmitBtn = document.getElementById('multi-rescue-submit-btn');

      // Set today's date as default
      document.getElementById('multi-rescue-date').value = new Date().toISOString().split('T')[0];

      // Update submit button text based on count
      document.getElementById('multi-rescue-count').addEventListener('input', function() {
        const count = parseInt(this.value);
        if (count >= 2) {
          multiRescueSubmitBtn.textContent = `Record ${count} Animals`;
        } else {
          multiRescueSubmitBtn.textContent = 'Record Rescue';
        }
      });

      function closeMultiRescueModal() {
        multiRescueModal.classList.remove('show');
        multiRescueForm.reset();
        document.getElementById('multi-rescue-date').value = new Date().toISOString().split('T')[0];
        multiRescueSubmitBtn.textContent = 'Record Rescue';
      }

      closeMultiRescueBtn.addEventListener('click', closeMultiRescueModal);
      cancelMultiRescueBtn.addEventListener('click', closeMultiRescueModal);
      multiRescueModal.addEventListener('click', (e) => {
        if (e.target === multiRescueModal) closeMultiRescueModal();
      });

      multiRescueForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const count = parseInt(document.getElementById('multi-rescue-count').value);
        if (isNaN(count) || count < 2) return;

        const source = document.getElementById('multi-rescue-source').value;
        const date = document.getElementById('multi-rescue-date').value;
        const location = document.getElementById('multi-rescue-location').value;
        const currentLocation = document.getElementById('multi-rescue-current-location').value;
        const notes = document.getElementById('multi-rescue-notes').value.trim();

        const batchId = generateBatchId();
        const batch = {
          id: batchId,
          count: count,
          completedCount: 0,
          rescueSource: source,
          rescueDate: date,
          barangay: location,
          currentLocation: currentLocation,
          notes: notes || '',
          createdAt: new Date().toISOString(),
          slots: []
        };

        // Create empty slots — no animals yet
        for (let i = 1; i <= count; i++) {
          batch.slots.push({
            index: i,
            status: 'awaiting',
            animalName: null,
            poolPetId: null
          });
        }

        multiRescueSubmitBtn.disabled = true;
        multiRescueSubmitBtn.textContent = 'Recording...';
        recordPoolRescueIntake(source, date, location, count, notes).then(intakeId => {
          batch.rescueIntakeId = intakeId;
          const batches = getBatches();
          batches.unshift(batch);
          saveBatches(batches);

          refreshCounts();
          renderTable();
          renderBatches();
          closeMultiRescueModal();
          showToast('Rescue Batch Recorded', { type: 'success', description: `Rescue batch recorded: ${count} animals from ${location}.` });
        }).catch(error => {
          showToast('Rescue Not Recorded', { type: 'error', description: error.message || 'Could not save the rescue intake.' });
        }).finally(() => {
          multiRescueSubmitBtn.disabled = false;
          if (multiRescueModal.classList.contains('show')) multiRescueSubmitBtn.textContent = `Record ${count} Animals`;
        });
      });

      // Rescue Batch Dossier Modal
      const batchDetailModal = document.getElementById('batch-detail-modal');
      const closeBatchDetailBtn = document.getElementById('close-batch-detail-modal');
      const closeBatchDetailBtnFooter = document.getElementById('close-batch-detail-btn');
      let currentDossierBatchId = null;

      function openBatchDossier(batchId) {
        const batches = getBatches();
        const batch = batches.find(b => b.id === batchId);
        if (!batch) return;

        // Migrate old format if needed
        if (!batch.slots && batch.animals) {
          batch.slots = batch.animals.map((a, i) => ({
            index: i + 1,
            status: a.status === 'completed' ? 'completed' : 'awaiting',
            animalName: a.name !== `Animal ${i+1}` ? a.name : null,
            poolPetId: a.poolPetId || null
          }));
          batch.completedCount = batch.slots.filter(s => s.status === 'completed').length;
          saveBatches(batches);
        }
        if (!batch.slots) {
          batch.slots = [];
          for (let i = 1; i <= (batch.count || 0); i++) {
            batch.slots.push({ index: i, status: 'awaiting', animalName: null, poolPetId: null });
          }
          saveBatches(batches);
        }

        currentDossierBatchId = batchId;
        const pct = batchProgressPct(batch);

        document.getElementById('batch-detail-title').textContent = 'Rescue Batch Dossier';
        document.getElementById('batch-detail-subtitle').textContent = `#${batch.id} \u2014 Complete individual animal profiles for this rescue batch.`;

        const body = document.getElementById('batch-detail-body');
        let slotsHtml = '';

        batch.slots.forEach(slot => {
          const isCompleted = slot.status === 'completed';
          const slotName = isCompleted ? slot.animalName : `Animal #${slot.index}`;
          const slotStatus = isCompleted ? 'Profile Completed' : 'Awaiting Profile';

          slotsHtml += `
            <div class="animal-slot ${isCompleted ? 'completed' : ''}">
              <div class="animal-slot-num">${isCompleted ? '\u2713' : slot.index}</div>
              <div class="animal-slot-info">
                <div class="animal-slot-name">${slotName}</div>
                <div class="animal-slot-status">${slotStatus}</div>
              </div>
              <div class="animal-slot-action">
                ${isCompleted
                  ? '<button class="btn-fill-profile done" disabled>Completed \u2713</button>'
                  : `<button class="btn-fill-profile" data-batch-id="${batch.id}" data-slot-index="${slot.index}">Fill Profile \u2192</button>`
                }
              </div>
            </div>
          `;
        });

        body.innerHTML = `
          <div class="dossier-summary-card">
            <div class="dossier-batch-id">#${batch.id}</div>
            <div class="dossier-count">${batch.count} Animals Rescued</div>
            <div class="dossier-tags">
              <span class="dossier-tag">${batch.barangay}</span>
              <span class="dossier-tag">${formatBatchDate(batch.rescueDate)}</span>
              <span class="dossier-tag">${batch.rescueSource}</span>
              <span class="dossier-tag">${batch.currentLocation}</span>
            </div>
            <div class="dossier-progress-bar">
              <div class="dossier-progress-fill" style="width:${pct}%;"></div>
            </div>
            <div class="dossier-progress-text">
              <span>Details: <strong>${batch.completedCount} / ${batch.count}</strong> completed</span>
              <span class="dossier-progress-pct">${pct}%</span>
            </div>
          </div>
          <div class="dossier-section-label">Animal Slots</div>
          ${slotsHtml}
        `;

        // Wire up "Fill Profile" buttons
        body.querySelectorAll('.btn-fill-profile:not(.done)').forEach(btn => {
          btn.addEventListener('click', () => {
            const bid = btn.dataset.batchId;
            const slotIdx = parseInt(btn.dataset.slotIndex);
            batchDetailModal.classList.remove('show');
            openFillProfile(bid, slotIdx);
          });
        });

        batchDetailModal.classList.add('show');
      }

      closeBatchDetailBtn.addEventListener('click', () => batchDetailModal.classList.remove('show'));
      closeBatchDetailBtnFooter.addEventListener('click', () => batchDetailModal.classList.remove('show'));
      batchDetailModal.addEventListener('click', (e) => {
        if (e.target === batchDetailModal) batchDetailModal.classList.remove('show');
      });

      // Fill Profile: opens single rescue form pre-filled with batch context
      let fillProfileBatchId = null;
      let fillProfileSlotIndex = null;

      function openFillProfile(batchId, slotIndex) {
        fillProfileBatchId = batchId;
        fillProfileSlotIndex = slotIndex;

        const batches = getBatches();
        const batch = batches.find(b => b.id === batchId);
        if (!batch) return;

        // Pre-fill rescue details from batch
        document.getElementById('rescue-source').value = batch.rescueSource;
        document.getElementById('rescue-date').value = batch.rescueDate;
        document.getElementById('rescue-location').value = batch.barangay;
        document.getElementById('rescue-current-location').value = batch.currentLocation;

        // Clear per-animal fields
        document.getElementById('rescue-pet-name').value = '';
        document.getElementById('rescue-species').value = 'Dog';
        document.getElementById('rescue-sex').value = 'Female';
        document.getElementById('rescue-age').value = '';
        document.getElementById('rescue-notes').value = batch.notes || '';

        // Show rescue modal
        rescueModal.classList.add('show');
      }

      // Patch rescue form submit to handle batch slot completion
      rescueForm.addEventListener('submit', function(e) {
        e.preventDefault();

        // Batch fill-profile mode
        if (fillProfileBatchId) {
          const petName = document.getElementById('rescue-pet-name').value.trim();
          if (!petName) return;

          const species = document.getElementById('rescue-species').value;
          const sex = document.getElementById('rescue-sex').value;
          const age = document.getElementById('rescue-age').value.trim() || 'Unknown';
          const source = document.getElementById('rescue-source').value;
          const date = document.getElementById('rescue-date').value;
          const location = document.getElementById('rescue-location').value;
          const currentLocation = document.getElementById('rescue-current-location').value;
          const notes = document.getElementById('rescue-notes').value.trim();

          const batchId = fillProfileBatchId;
          const slotIdx = fillProfileSlotIndex;
          const sourceBatch = getBatches().find(batch => batch.id === batchId);
          fillProfileBatchId = null;
          fillProfileSlotIndex = null;

          const now = new Date();
          const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
          const shortDate = `${monthNames[now.getMonth()]} ${String(now.getDate()).padStart(2,'0')}`;

          const newPoolPet = {
            id: 'pet-' + Date.now(),
            name: petName,
            species: species,
            breed: species === 'Dog' ? 'Aspin' : (species === 'Cat' ? 'Domestic Shorthair' : 'Local Breed'),
            gender: sex,
            age: age,
            rescueSource: source,
            rescueDate: shortDate,
            fullRescueDate: date,
            adoptionStatus: 'Pending Review',
            listingStatus: 'Not Listed',
          image: rescuePhotoData || (species === 'Dog' ? 'aspin4.jpeg' : 'aspin5.webp'),
            readyDate: shortDate,
            notes: notes || `Rescued via batch ${batchId}. Slot ${slotIdx}.`,
            batchId: batchId,
            rescueIntakeId: sourceBatch ? sourceBatch.rescueIntakeId : 0,
            readiness: { vaccination: 'Not specified', health: 'Not assessed', spayNeuter: 'Not specified', temperament: 'Not assessed' }
          };

          const poolPets = getPoolPets();
          poolPets.unshift(newPoolPet);
          savePoolPets(poolPets);
          syncToDB(newPoolPet);

          const batches = getBatches();
          const batch = batches.find(b => b.id === batchId);
          if (batch) {
            const slot = batch.slots.find(s => s.index === slotIdx);
            if (slot) {
              slot.status = 'completed';
              slot.animalName = petName;
              slot.poolPetId = newPoolPet.id;
              batch.completedCount = batch.slots.filter(s => s.status === 'completed').length;
            }
            saveBatches(batches);
          }

          INTAKE_REGISTRY.unshift({
            id: newPoolPet.id, name: petName, species: species,
            breed: newPoolPet.breed, gender: sex, age: newPoolPet.age,
            rescueSource: source, rescueDate: date, shortRescueDate: shortDate,
            barangay: location, currentLocation: currentLocation,
            image: newPoolPet.image,
            readiness: { vaccination: 'Not specified', health: 'Not assessed', spayNeuter: 'Not specified', temperament: 'Not assessed' },
            notes: notes || ''
          });

          try {
            const storedIntakes = JSON.parse(localStorage.getItem(INTAKE_KEY)) || [];
            storedIntakes.unshift(INTAKE_REGISTRY[0]);
            localStorage.setItem(INTAKE_KEY, JSON.stringify(storedIntakes));
          } catch(ex) {}

          populatePetSelect();
          refreshCounts();
          renderTable();
          renderBatches();
          rescueModal.classList.remove('show');
          rescueForm.reset();
          resetRescuePhoto();

          const updatedBatch = getBatches().find(b => b.id === batchId);
          if (updatedBatch && updatedBatch.completedCount < updatedBatch.count) {
            setTimeout(() => openBatchDossier(batchId), 200);
          }

          showToast('Batch Updated', { type: 'success', description: `${petName} added to adoption pool. Batch ${batchId}: ${updatedBatch ? updatedBatch.completedCount + '/' + updatedBatch.count : ''} completed.` });
          return;
        }

        // Single rescue mode
        handleSingleRescueSubmit(e);
      });

      // Renamed original handler for non-batch rescues
      function handleSingleRescueSubmit(e) {
        e.preventDefault();
        const petName = document.getElementById('rescue-pet-name').value.trim();
        const species = document.getElementById('rescue-species').value;
        const sex = document.getElementById('rescue-sex').value;
        const age = document.getElementById('rescue-age').value.trim() || 'Unknown';
        const source = document.getElementById('rescue-source').value;
        const date = document.getElementById('rescue-date').value;
        const location = document.getElementById('rescue-location').value;
        const currentLocation = document.getElementById('rescue-current-location').value;
        const notes = document.getElementById('rescue-notes').value.trim();

        const existingPets = getPoolPets();
        const duplicate = existingPets.find(p => p.name === petName && p.species === species && p.gender === sex);
        if (duplicate) {
          showToast('Duplicate Pet', { type: 'error', description: `${petName} is already in the adoption pool.` });
          closeRescueModal();
          rescueForm.reset();
          resetRescuePhoto();
          return;
        }

        const now = new Date();
        const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        const shortDate = `${monthNames[now.getMonth()]} ${String(now.getDate()).padStart(2,'0')}`;

        const newPoolPet = {
          id: 'pet-' + Date.now(),
          name: petName,
          species: species,
          breed: species === 'Dog' ? 'Aspin' : (species === 'Cat' ? 'Domestic Shorthair' : 'Local Breed'),
          gender: sex,
          age: age,
          rescueSource: source,
          rescueDate: shortDate,
          fullRescueDate: date,
          adoptionStatus: 'Pending Review',
          listingStatus: 'Not Listed',
          image: rescuePhotoData || (species === 'Dog' ? 'aspin4.jpeg' : 'aspin5.webp'),
          readyDate: shortDate,
          notes: notes || 'Rescued and checked into DCVS Shelter.',
          readiness: { vaccination: 'Not specified', health: 'Not assessed', spayNeuter: 'Not specified', temperament: 'Not assessed' }
        };

        const submitButton = rescueForm.querySelector('button[type="submit"]');
        const submitLabel = submitButton ? submitButton.textContent : '';
        if (submitButton) { submitButton.disabled = true; submitButton.textContent = 'Recording...'; }
        recordPoolRescueIntake(source, date, location, 1, notes).then(intakeId => {
          newPoolPet.rescueIntakeId = intakeId;
          const poolPets = getPoolPets();
          poolPets.unshift(newPoolPet);
          savePoolPets(poolPets);
          syncToDB(newPoolPet);

          INTAKE_REGISTRY.unshift({
            id: newPoolPet.id, name: petName, species: species,
            breed: newPoolPet.breed, gender: sex, age: age,
            rescueSource: source, rescueDate: date, shortRescueDate: shortDate,
            barangay: location, currentLocation: currentLocation,
            image: newPoolPet.image,
            readiness: { vaccination: 'Not specified', health: 'Not assessed', spayNeuter: 'Not specified', temperament: 'Not assessed' },
            notes: notes || ''
          });

          try {
            const storedIntakes = JSON.parse(localStorage.getItem(INTAKE_KEY)) || [];
            storedIntakes.unshift(INTAKE_REGISTRY[0]);
            localStorage.setItem(INTAKE_KEY, JSON.stringify(storedIntakes));
          } catch(e) {}

          populatePetSelect();
          refreshCounts();
          renderTable();
          renderBatches();
          closeRescueModal();
          showToast('Added to Pool', { type: 'success', description: `${petName} added to the adoption pool.` });
        }).catch(error => {
          showToast('Rescue Not Recorded', { type: 'error', description: error.message || 'Could not save the rescue intake.' });
        }).finally(() => {
          if (submitButton) { submitButton.disabled = false; submitButton.textContent = submitLabel; }
        });
      }

      // Toast Notification Helper — availability page style
      var _toastTimers = [];
      function showToast(title, opts) {
        opts = opts || {};
        var type = opts.type || 'success';
        var desc = opts.description || opts.desc || '';
        var duration = opts.duration != null ? opts.duration : 4000;

        var container = document.getElementById('toast-container');
        var el = document.createElement('div');
        el.className = 'toast-pill ' + type;

        var icons = {
          success: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
          error: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
          info: '<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
        };

        var html = (icons[type] || icons.success);
        html += '<div class="toast-body"><p class="toast-title">' + title + '</p>';
        if (desc) html += '<p class="toast-desc">' + desc + '</p>';
        html += '</div>';
        html += '<button class="toast-close" aria-label="Close"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
        el.innerHTML = html;

        container.appendChild(el);
        requestAnimationFrame(function() {
          requestAnimationFrame(function() { el.classList.add('show'); });
        });

        function dismissToast(node) {
          node.classList.remove('show');
          node.classList.add('hiding');
          setTimeout(function() { node.remove(); }, 300);
        }

        var closeBtn = el.querySelector('.toast-close');
        closeBtn.addEventListener('click', function() { dismissToast(el); });

        var timer = setTimeout(function() { dismissToast(el); }, duration);
        el._timer = timer;
        el.addEventListener('mouseenter', function() { clearTimeout(el._timer); });
        el.addEventListener('mouseleave', function() { el._timer = setTimeout(function() { dismissToast(el); }, 2000); });
      }

      // Tab switching
      document.querySelectorAll('.pool-tab').forEach(tab => {
        tab.addEventListener('click', () => {
          document.querySelectorAll('.pool-tab').forEach(t => t.classList.remove('active'));
          document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
          tab.classList.add('active');
          document.getElementById(tab.dataset.tab).classList.add('active');
        });
      });

      // Batch filter events
      document.getElementById('batch-search-input')?.addEventListener('input', renderBatches);
      document.getElementById('batch-filter-source')?.addEventListener('change', renderBatches);
      document.getElementById('batch-filter-status')?.addEventListener('change', renderBatches);
      document.getElementById('btn-reset-batch-filters')?.addEventListener('click', () => {
        document.getElementById('batch-search-input').value = '';
        document.getElementById('batch-filter-source').value = 'all';
        document.getElementById('batch-filter-status').value = 'all';
        renderBatches();
      });

      // Auto-transition: Pending Review + past scheduledDate → Available
      function checkScheduledTransitions() {
        const pets = getPoolPets();
        const today = new Date();
        today.setHours(0,0,0,0);
        let changed = false;
        pets.forEach(p => {
          if (p.adoptionStatus === 'Pending Review' && p.scheduledDate) {
            const sDate = new Date(p.scheduledDate + 'T00:00:00');
            if (sDate <= today) {
              p.adoptionStatus = 'Available';
              p.scheduledDate = '';
              changed = true;
            }
          }
        });
        if (changed) {
          savePoolPets(pets);
          pets.filter(p => p.adoptionStatus === 'Available').forEach(p => syncToDB(p));
        }
      }

      function repairPublishedPhotos() {
        getPoolPets()
          .filter(p => p.listingStatus === 'Published' && typeof p.image === 'string' && p.image.indexOf('data:image/') === 0)
          .forEach(syncToDB);
      }

      // Initial execution — load from DB if localStorage is empty
      checkScheduledTransitions();
      let poolReady;
      if (getPoolPets().length === 0) {
        poolReady = loadFromDB().then(() => {
          repairPublishedPhotos();
          refreshCounts();
          renderBatches();
          renderTable();
        });
      } else {
        repairPublishedPhotos();
        refreshCounts();
        renderBatches();
        renderTable();
        poolReady = Promise.resolve();
      }

      // Refresh adoption status + adopted date from DB (e.g. adopted via resident flow)
      poolReady.then(() => syncAdoptedFromDB()).then(() => backfillLegacyRescueIntakes()).catch(() => {});

      // Re-render when page is restored from browser back-forward cache
      window.addEventListener('pageshow', (e) => {
        if (e.persisted) {
          if (getPoolPets().length === 0) {
            loadFromDB().then(() => {
              refreshCounts();
              renderBatches();
              renderTable();
            });
          } else {
            refreshCounts();
            renderBatches();
            renderTable();
          }
        }
      });
    });

    // syncToDB is only called explicitly from rescue/toggle/adopt actions
    // (removed background sync that was creating duplicate DB rows)
  </script>

</main>
  <script>
  (function () {
  var dataNode = document.getElementById('adoption-review-data');
  var applicationQueue = document.getElementById('adoption-application-queue');
  var notice = document.getElementById('adoption-review-notice');
  if (!dataNode || !applicationQueue || !notice) return;

    var queueData;
    try {
      queueData = JSON.parse(dataNode.textContent);
    } catch (error) {
      notice.textContent = 'The review queue could not be displayed. Refresh the page or contact support.';
      return;
    }
    var csrf = <?php echo json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.adoptionWorkflowCsrf = csrf;
    var documentDialog = document.getElementById('adoption-document-dialog');
    var documentPreview = document.getElementById('adoption-document-preview');
    var actionDialog = document.getElementById('adoption-action-dialog');
    var actionDialogTitle = document.getElementById('adoption-action-dialog-title');
    var actionDialogDescription = document.getElementById('adoption-action-dialog-description');
    var actionDialogConfirm = document.getElementById('adoption-action-dialog-confirm');
    var actionDialogCancel = document.getElementById('adoption-action-dialog-cancel');
    var actionScheduleField = document.getElementById('adoption-action-schedule-field');
    var actionPickupAt = document.getElementById('adoption-action-pickup-at');
    var actionDialogTrigger = null;

    function closeDocumentDialog() {
      documentDialog.classList.remove('open');
      documentDialog.setAttribute('aria-hidden', 'true');
      documentPreview.src = 'about:blank';
    }
    document.getElementById('adoption-document-dialog-close').addEventListener('click', closeDocumentDialog);
    documentDialog.addEventListener('click', function (event) {
      if (event.target === documentDialog) closeDocumentDialog();
    });
    function closeActionDialog() {
      actionDialog.classList.remove('open');
      actionDialog.setAttribute('aria-hidden', 'true');
      if (actionDialogTrigger) actionDialogTrigger.focus();
      actionDialogTrigger = null;
    }
    actionDialogCancel.addEventListener('click', closeActionDialog);
    actionDialog.addEventListener('click', function (event) {
      if (event.target === actionDialog) closeActionDialog();
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        if (actionDialog.classList.contains('open')) closeActionDialog();
        else if (documentDialog.classList.contains('open')) closeDocumentDialog();
      }
    });
    function confirmReviewAction(title, description, confirmLabel, danger, trigger, callback, requiresPickupSchedule) {
      actionDialogTitle.textContent = title;
      actionDialogDescription.textContent = description;
      actionDialogConfirm.textContent = confirmLabel;
      actionDialogConfirm.classList.toggle('danger', !!danger);
      actionScheduleField.hidden = !requiresPickupSchedule;
      actionPickupAt.required = !!requiresPickupSchedule;
      actionPickupAt.value = '';
      var minimumDate = new Date(Date.now() + 60000);
      minimumDate.setMinutes(minimumDate.getMinutes() - minimumDate.getTimezoneOffset());
      actionPickupAt.min = minimumDate.toISOString().slice(0, 16);
      actionDialogTrigger = trigger;
      actionDialog.classList.add('open');
      actionDialog.setAttribute('aria-hidden', 'false');
      if (requiresPickupSchedule) actionPickupAt.focus();
      else actionDialogConfirm.focus();
      actionDialogConfirm.onclick = function () {
        if (requiresPickupSchedule) {
          if (!actionPickupAt.value || new Date(actionPickupAt.value).getTime() <= Date.now()) {
            actionPickupAt.setCustomValidity('Choose a future date and time.');
            actionPickupAt.reportValidity();
            return;
          }
          actionPickupAt.setCustomValidity('');
        }
        closeActionDialog();
        callback(requiresPickupSchedule ? actionPickupAt.value : '');
      };
    }

    function addText(parent, tag, value, className) {
      var node = document.createElement(tag);
      node.textContent = value == null ? '' : String(value);
      if (className) node.className = className;
      parent.appendChild(node);
      return node;
    }
    function addField(parent, labelText, name, type, value) {
      var wrap = document.createElement('div');
      var label = document.createElement('label');
      label.textContent = labelText;
      var input = document.createElement(type === 'textarea' ? 'textarea' : 'input');
      if (type !== 'textarea') input.type = type;
      input.name = name;
      if (value) input.value = value;
      label.appendChild(input);
      wrap.appendChild(label);
      parent.appendChild(wrap);
      return input;
    }
    function addButton(parent, label, className, callback) {
      var button = document.createElement('button');
      button.type = 'button';
      button.textContent = label;
      if (className) button.className = className;
      button.addEventListener('click', callback);
      parent.appendChild(button);
      return button;
    }
    function readableDate(value) {
      if (!value) return 'Not set';
      var date = new Date(value.replace(' ', 'T'));
      return isNaN(date.getTime()) ? value : date.toLocaleString();
    }
    function sendReview(formData, button) {
      button.disabled = true;
      notice.hidden = false;
      notice.textContent = 'Saving review...';
      fetch('adoption-workflow-handler.php', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(function (response) { return response.json().then(function (body) { return { status: response.status, body: body }; }); })
        .then(function (result) {
          notice.textContent = result.body.message || 'Review could not be saved.';
          if (result.body.success) {
            window.setTimeout(function () { window.location.reload(); }, 1600);
          } else {
            button.disabled = false;
          }
        })
        .catch(function () {
          notice.textContent = 'The review request could not be sent. Check your connection and try again.';
          button.disabled = false;
        });
    }

    (queueData.applications || []).forEach(function (item) {
      var card = document.createElement('article');
      card.className = 'adoption-review-card';
      var cardHeader = document.createElement('div');
      cardHeader.className = 'adoption-review-summary';
      if (item.photo_url) {
        var petPhoto = document.createElement('img');
        petPhoto.className = 'adoption-review-pet-photo';
        petPhoto.src = item.photo_url;
        petPhoto.alt = '';
        cardHeader.appendChild(petPhoto);
      }
      var heading = document.createElement('div');
      heading.className = 'adoption-review-summary-copy';
      var petTitle = addText(heading, 'h3', item.pet_name);
      petTitle.className = 'adoption-review-pet-title';
      addText(petTitle, 'span', '(' + [item.species, item.breed].filter(Boolean).join(' · ') + ')', 'adoption-review-pet-meta');
      var applicantAge = item.date_of_birth ? calculateAge(item.date_of_birth) + ' yrs' : 'age not provided';
      addText(heading, 'p', 'Applicant: ' + item.full_name + ' (' + applicantAge + ') · Brgy. ' + (item.barangay_name || 'Not provided'), 'adoption-review-applicant');
      addText(heading, 'p', 'Submitted: ' + readableDate(item.application_date) + ' · Phone: ' + (item.phone || 'Not provided'), 'adoption-review-submitted');
      if (item.status === 'approved' || item.status === 'expired') {
        addText(heading, 'p', 'Pickup period: ' + window.adoptionPickupPeriodLabel(item.approved_pickup_at, item.status), 'adoption-review-submitted');
      }
      cardHeader.appendChild(heading);

      var summaryActions = document.createElement('div');
      summaryActions.className = 'adoption-review-summary-actions';
      var statusLabels = {pending: 'Pending Application', under_review: 'Under Review', needs_info: 'Needs Info', approved: 'In Progress', adopted: 'Adopted', expired: 'Expired / Unclaimed'};
      var statusText = statusLabels[item.status] || item.status.replace(/_/g, ' ');
      var statusLabel = addText(summaryActions, 'span', statusText, 'adoption-review-status');
      if (item.status === 'pending') statusLabel.classList.add('pending');
      if (item.status === 'under_review') statusLabel.classList.add('active');
      if (item.status === 'needs_info') statusLabel.classList.add('needs-info');
      if (item.status === 'approved') statusLabel.classList.add('active');
      if (item.status === 'adopted') statusLabel.classList.add('active');
      if (item.status === 'expired') statusLabel.classList.add('needs-info');
      var details = document.createElement('div');
      details.className = 'adoption-review-details';
      details.hidden = true;
      var detailsToggle = addButton(summaryActions, 'Review Details  ▾', 'adoption-review-toggle', function () {
        details.hidden = !details.hidden;
        detailsToggle.textContent = details.hidden ? 'Review Details  ▾' : 'Collapse  ▴';
        detailsToggle.setAttribute('aria-expanded', String(!details.hidden));
      });
      detailsToggle.setAttribute('aria-expanded', 'false');
      cardHeader.appendChild(summaryActions);
      card.appendChild(cardHeader);

      addText(details, 'h4', 'Qualification & compliance verification', 'adoption-review-details-title');
      var checks = document.createElement('div');
      checks.className = 'adoption-review-checks';
      function addCheck(label, passed) {
        addText(checks, 'span', (passed ? '✓ ' : '! ') + label, 'adoption-review-check' + (passed ? '' : ' pending'));
      }
      var age = item.date_of_birth ? calculateAge(item.date_of_birth) : null;
      addCheck(age === null ? 'Age not provided' : 'Legal age: ' + age + ' yrs', age !== null && age >= 18);
      addCheck(item.residency_proof_file ? 'Residency proof submitted' : 'Residency proof missing', !!item.residency_proof_file);
      addCheck('Housing: ' + (item.housing_type || 'Not provided'), !!item.housing_type && (item.housing_type !== 'rented' || Number(item.landlord_permission) === 1));
      addCheck(item.agreed_at ? 'Adoption commitments accepted' : 'Agreement not recorded', !!item.agreed_at);
      details.appendChild(checks);

      var facts = document.createElement('div');
      facts.className = 'adoption-review-facts';
      function addFact(label, value) {
        var fact = document.createElement('div');
        fact.className = 'adoption-review-fact';
        addText(fact, 'span', label, 'adoption-review-fact-label');
        addText(fact, 'span', value || 'Not provided', 'adoption-review-fact-value');
        facts.appendChild(fact);
      }
      var housing = (item.housing_type || 'Not provided');
      if (item.housing_type === 'rented') housing += Number(item.landlord_permission) ? ' · Landlord permission confirmed' : ' · No landlord permission';
      addFact('Housing type & yard', housing + ' · ' + (item.home_type || 'Home type not provided') + ' · ' + (Number(item.has_fenced_yard) ? 'Fenced yard' : 'No fenced yard'));
      addFact('Household composition', item.household_members ? item.household_members + ' household member(s)' : 'Not provided');
      addFact('Existing pets', Number(item.has_other_pets) ? (item.other_pets_details || 'Yes') : 'No');
      addFact('Pet experience', item.pet_care_experience);
      addFact('Contact details', (item.phone || 'No phone') + ' / ' + (item.email || 'No email'));
      addFact('Current application status', statusText);
      if (item.approved_pickup_at) addFact('Scheduled DCVS visit', readableDate(item.approved_pickup_at));
      if (item.status === 'approved' || item.status === 'expired') {
        addFact('Pickup period', window.adoptionPickupPeriodLabel(item.approved_pickup_at, item.status));
      }
      if (item.finalized_at) addFact('Confirmed/Adopted Date', readableDate(item.finalized_at));
      details.appendChild(facts);
      var reasonSummary = document.createElement('div');
      reasonSummary.className = 'adoption-review-reason';
      addText(reasonSummary, 'span', 'Reason for adoption & care intentions', 'adoption-review-fact-label');
      addText(reasonSummary, 'div', item.reason_for_adoption || 'Not provided', 'adoption-review-fact-value');
      details.appendChild(reasonSummary);
      if (item.requested_info) addText(details, 'p', 'Information requested: ' + item.requested_info + ' · Deadline: ' + readableDate(item.info_deadline), 'adoption-review-submitted');
      if (item.resident_response) addText(details, 'p', 'Resident response: ' + item.resident_response, 'adoption-review-submitted');
      if (item.interview_at) addText(details, 'p', 'Interview: ' + readableDate(item.interview_at), 'adoption-review-submitted');
      if (item.home_visit_at) addText(details, 'p', 'Home visit: ' + readableDate(item.home_visit_at), 'adoption-review-submitted');
      var documents = document.createElement('p');
      documents.className = 'adoption-review-documents';
      var proofButton = document.createElement('button');
      proofButton.type = 'button';
      proofButton.className = 'adoption-review-toggle';
      proofButton.style.minWidth = '0';
      proofButton.textContent = 'View proof of residency';
      proofButton.addEventListener('click', function () {
        documentPreview.src = 'adoption-document.php?kind=residency&id=' + encodeURIComponent(item.application_id) + '&view=1';
        documentDialog.classList.add('open');
        documentDialog.setAttribute('aria-hidden', 'false');
        document.getElementById('adoption-document-dialog-close').focus();
      });
      documents.appendChild(proofButton);
      details.appendChild(documents);

      var reviewFields = document.createElement('div');
      reviewFields.className = 'adoption-review-grid';
      reviewFields.hidden = true;
      var reason = addField(reviewFields, 'Information requested / rejection reason', 'reason', 'textarea', '');
      var interview = addField(reviewFields, 'Interview (optional)', 'interview_at', 'datetime-local', '');
      var homeVisit = addField(reviewFields, 'Home visit (optional)', 'home_visit_at', 'datetime-local', '');
      var homeVisitNotes = addField(reviewFields, 'Home visit notes (optional)', 'home_visit_notes', 'textarea', '');
      details.appendChild(reviewFields);
      var actions = document.createElement('div');
      actions.className = 'adoption-review-actions';
      function submitDecision(decision, button) {
        if (decision !== 'approved' && !reason.value.trim()) {
          notice.hidden = false;
          notice.textContent = decision === 'rejected' ? 'Enter a reason before rejecting the application.' : 'Enter the information requested before sending.';
          reason.focus();
          return;
        }
        var formData = new FormData();
        formData.set('csrf_token', csrf);
        formData.set('staff_action', 'review_application');
        formData.set('application_id', item.application_id);
        formData.set('decision', decision);
        formData.set('reason', reason.value.trim());
        formData.set('interview_at', interview.value);
        formData.set('home_visit_at', homeVisit.value);
        formData.set('home_visit_notes', homeVisitNotes.value.trim());
        var confirmation = {
          approved: {
            title: 'Approve application and schedule visit?',
            description: 'Approve ' + item.full_name + '\'s application and choose the date and time to visit the DCVS office. The pet will remain reserved until staff finalizes the adoption after pickup.',
            label: 'Approve & Schedule Visit',
            danger: false
          },
          needs_info: {
            title: 'Send information request?',
            description: 'Send this request for more information or schedule a visit with ' + item.full_name + '?',
            label: 'Send Request',
            danger: false
          },
          rejected: {
            title: 'Reject application?',
            description: 'Reject ' + item.full_name + '\'s adoption application? The pet will be made available again.',
            label: 'Reject Application',
            danger: true
          }
        }[decision];
        confirmReviewAction(confirmation.title, confirmation.description, confirmation.label, confirmation.danger, button, function (pickupAt) {
          if (decision === 'approved') formData.set('pickup_at', pickupAt);
          sendReview(formData, button);
        }, decision === 'approved');
      }
      if (item.status !== 'approved' && item.status !== 'adopted') {
      addButton(actions, 'Approve & Schedule Visit', '', function (event) {
        submitDecision('approved', event.currentTarget);
      });
      var requestButton = addButton(actions, 'Request Info / Schedule Visit', 'secondary', function () {
        reviewFields.hidden = false;
        reason.placeholder = 'Enter the information needed from the applicant.';
        reason.focus();
      });
      var rejectButton = addButton(actions, 'Reject Application', 'reject', function () {
        reviewFields.hidden = false;
        reason.placeholder = 'Enter the reason for rejection.';
        reason.focus();
      });
      var sendRequestButton = addButton(actions, 'Send Request', 'secondary', function (event) {
        submitDecision('needs_info', event.currentTarget);
      });
      sendRequestButton.style.display = 'none';
      var confirmRejectButton = addButton(actions, 'Confirm Rejection', 'confirm-reject', function (event) {
        submitDecision('rejected', event.currentTarget);
      });
      confirmRejectButton.style.display = 'none';
      requestButton.addEventListener('click', function () { sendRequestButton.style.display = 'inline-flex'; confirmRejectButton.style.display = 'none'; });
      rejectButton.addEventListener('click', function () { confirmRejectButton.style.display = 'inline-flex'; sendRequestButton.style.display = 'none'; });
      } else if (item.status === 'approved') {
        addText(actions, 'span', 'The visit is scheduled. Finalize the adoption from the Adoption Pool drawer after pickup.', 'adoption-review-submitted');
      }
      details.appendChild(actions);
      card.appendChild(details);
      applicationQueue.appendChild(card);
    });

    function calculateAge(dateOfBirth) {
      if (!dateOfBirth) return 'Unknown';
      var birthDate = new Date(dateOfBirth + 'T00:00:00');
      if (isNaN(birthDate.getTime())) return 'Unknown';
      var today = new Date();
      var age = today.getFullYear() - birthDate.getFullYear();
      if (today.getMonth() < birthDate.getMonth() || (today.getMonth() === birthDate.getMonth() && today.getDate() < birthDate.getDate())) age--;
      return age;
    }
  })();
  </script>
</body>
</html>