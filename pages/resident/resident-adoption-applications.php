<?php
ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
session_set_cookie_params([
    'lifetime' => 30 * 24 * 60 * 60,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? '',
    'secure' => !empty($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/adoption-workflow.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'resident') {
    header('Location: login.php');
    exit;
}

adoptionReleaseExpiredReservations($conn);

$residentId = (int)$_SESSION['user_id'];
$applyListingId = (int)($_GET['apply'] ?? 0);
$residentStmt = $conn->prepare(
    'SELECT u.full_name, u.email, u.phone, u.birthdate, u.status, b.barangay_name, b.district
     FROM users u LEFT JOIN barangays b ON b.barangay_id = u.barangay_id
     WHERE u.user_id = ? AND u.role = \'resident\''
);
$residentStmt->bind_param('i', $residentId);
$residentStmt->execute();
$resident = $residentStmt->get_result()->fetch_assoc();
$residentStmt->close();

$selectedPet = null;
if ($applyListingId > 0) {
    $petStmt = $conn->prepare(
        "SELECT listing_id, name, species, breed, age, gender, description, photo_url
         FROM pet_listings WHERE listing_id = ? AND status = 'available'"
    );
    $petStmt->bind_param('i', $applyListingId);
    $petStmt->execute();
    $selectedPet = $petStmt->get_result()->fetch_assoc();
    $petStmt->close();
}

$hasApplicationForPet = $selectedPet && adoptionHasActiveApplicationForListing($conn, $applyListingId);
$checks = [
    'Account verified & active' => !empty($resident) && $resident['status'] === 'active',
    'Resident of Dasmariñas City' => !empty($resident['district']) && stripos((string)$resident['district'], 'Dasmar') === 0,
    'No pending application for this pet' => !$hasApplicationForPet,
];
$canApply = $selectedPet && !in_array(false, $checks, true);
$activePage = 'adoption';
$pageTitle = 'Adoption Application';
?>
<!DOCTYPE html>
<html lang="en" class="resident-portal">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Adoption Application - DCVS Resident Portal</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/responsive.css">
  <style>
    #adoptionApplicationDialog.modal{z-index:2000;background:rgba(0,0,0,.8);padding:1rem}
    #adoptionApplicationDialog .sd-dialog{width:100%;display:flex;justify-content:center}
    #adoptionApplicationDialog .sd-dialog-content{position:relative;box-sizing:border-box;width:100%;max-width:680px;max-height:90vh;padding:24px;display:flex;flex-direction:column;gap:16px;overflow:hidden;border:1px solid #e4e4e7;border-radius:8px;background:#fff;box-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1);animation:modalIn .2s ease;font-family:'Inter','Segoe UI',sans-serif;color:#18181b}
    #adoptionApplicationDialog .sd-dialog-header{display:flex;flex-direction:column;gap:6px;padding-right:28px;text-align:left}
    #adoptionApplicationDialog .sd-dialog-title{margin:0;font-family:'Inter',sans-serif;font-size:18px;font-weight:600;line-height:1;letter-spacing:-.025em;color:#18181b}
    #adoptionApplicationDialog .sd-dialog-description{margin:0;font-size:14px;line-height:1.5;color:#71717a}
    #adoptionApplicationDialog .sd-dialog-close{position:absolute;top:16px;right:16px;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:4px;background:transparent;color:#71717a;cursor:pointer;transition:all .15s ease}
    #adoptionApplicationDialog .sd-dialog-close:hover{background:#f4f4f5;color:#18181b}
    #adoptionApplicationDialog .application-body{min-height:0;overflow-y:auto;display:flex;flex-direction:column;gap:20px}
    #adoptionApplicationDialog .pet-summary{display:flex;gap:14px;align-items:center;padding:0;border:0;border-radius:0;background:transparent}
    #adoptionApplicationDialog .pet-summary img{width:76px;height:76px;flex:0 0 76px;object-fit:cover;border-radius:8px;background:#e4e4e7}
    #adoptionApplicationDialog .pet-summary strong{font-size:15px;font-weight:600;color:#18181b}
    #adoptionApplicationDialog .pet-summary p{margin:3px 0 0;color:#71717a;font-size:12px}
    #adoptionApplicationDialog .qualification{padding:12px;border:1px solid #e4e4e7;border-radius:8px;background:#fafafa}
    #adoptionApplicationDialog .qualification h2,#adoptionApplicationDialog .application-section h2{margin:0 0 10px;font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:#71717a;font-weight:600}
    #adoptionApplicationDialog .check-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px 12px}
    #adoptionApplicationDialog .check-item{font-size:13px;color:#3f3f46}
    #adoptionApplicationDialog .check-item::before{content:'✓';display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;margin-right:7px;border-radius:50%;background:#dcfce7;color:#16834b;font-weight:700}
    #adoptionApplicationDialog .check-item.failed::before{content:'!';background:#fee2e2;color:#b91c1c}
    #adoptionApplicationDialog .application-section{padding:0;margin:0;border:0}
    #adoptionApplicationDialog form#adoption-application-form{display:flex;flex-direction:column;gap:22px}
    #adoptionApplicationDialog .application-section h2{color:#18181b;margin-bottom:12px}
    #adoptionApplicationDialog .application-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 16px}
    #adoptionApplicationDialog .application-field label{display:block;margin-bottom:7px;font-size:14px;line-height:1.4;color:#18181b;font-weight:500}
    #adoptionApplicationDialog .required-mark{color:#dc2626;margin-left:3px;font-weight:600}
    #adoptionApplicationDialog .application-field input:not([type=checkbox]),#adoptionApplicationDialog .application-field select,#adoptionApplicationDialog .application-field textarea{box-sizing:border-box;width:100%;min-height:36px;padding:0 12px;border:1px solid #e4e4e7;border-radius:6px;background:#fff;color:#18181b;font:14px 'Inter',sans-serif;box-shadow:0 1px 2px rgba(0,0,0,.05);outline:none;transition:border-color .15s ease,box-shadow .15s ease}
    #adoptionApplicationDialog .application-field input:not([type=checkbox]):focus,#adoptionApplicationDialog .application-field select:focus,#adoptionApplicationDialog .application-field textarea:focus{border-color:#18181b;box-shadow:0 0 0 1px #18181b}
    #adoptionApplicationDialog .application-field input[readonly]{background:#fafafa;color:#71717a}
    #adoptionApplicationDialog .application-field textarea{min-height:76px;padding:8px 12px;resize:vertical;line-height:1.5}
    #adoptionApplicationDialog .application-field small{display:block;margin-top:4px;color:#71717a;font-size:12px}
    #adoptionApplicationDialog .application-field.full{grid-column:1/-1}
    #adoptionApplicationDialog .upload-field{height:auto!important;min-height:70px!important;padding:12px!important;border-style:dashed!important;background:#fafafa!important}
    #adoptionApplicationDialog .agreement-box{padding:14px;border:1px solid #e4e4e7;border-radius:8px;background:#fafafa}
    #adoptionApplicationDialog .agreement-box strong{display:block;margin-bottom:9px;font-size:14px;font-weight:600}
    #adoptionApplicationDialog .agreement-box label{display:flex;align-items:flex-start;gap:8px;margin:8px 0;font-size:13px;line-height:1.45;color:#3f3f46}
    #adoptionApplicationDialog .agreement-box input{margin-top:3px}
    #adoptionApplicationDialog .sd-dialog-footer{display:flex;flex-direction:row-reverse;justify-content:flex-start;gap:8px;padding-top:0}
    #adoptionApplicationDialog .application-footer-note{margin:0 auto 0 0;color:#71717a;font-size:12px}
    #adoptionApplicationDialog .sd-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:36px;padding:0 16px;border:1px solid transparent;border-radius:6px;font:500 14px/1 'Inter',sans-serif;white-space:nowrap;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.05);transition:background-color .15s ease,border-color .15s ease,color .15s ease}
    #adoptionApplicationDialog .sd-btn-default{background:#18181b;color:#fafafa}
    #adoptionApplicationDialog .sd-btn-default:hover{background:#27272a}
    #adoptionApplicationDialog .sd-btn-outline{border-color:#e4e4e7;background:#fff;color:#18181b}
    #adoptionApplicationDialog .sd-btn-outline:hover{background:#f4f4f5}
    #adoptionApplicationDialog .sd-btn:disabled{opacity:.5;cursor:not-allowed;pointer-events:none}
    #adoptionApplicationDialog .application-notice{display:none;padding:10px 12px;border:1px solid #e4e4e7;border-radius:6px;font-size:13px;background:#fafafa;color:#3f3f46}
    #adoptionApplicationDialog .application-notice.error{border-color:#fecaca;background:#fef2f2;color:#991b1b}
    @media(max-width:640px){#adoptionApplicationDialog.modal{padding:0}#adoptionApplicationDialog .sd-dialog-content{max-height:100dvh;border-radius:0;padding:16px}#adoptionApplicationDialog .pet-summary img{width:64px;height:64px;flex-basis:64px}#adoptionApplicationDialog .application-grid,#adoptionApplicationDialog .check-grid{grid-template-columns:1fr}#adoptionApplicationDialog .application-field.full{grid-column:auto}#adoptionApplicationDialog .sd-dialog-footer{flex-wrap:wrap}#adoptionApplicationDialog .application-footer-note{flex-basis:100%}#adoptionApplicationDialog .sd-dialog-footer .sd-btn{flex:1}}
  </style>
</head>
<body class="resident-portal">
<?php include __DIR__ . '/../../includes/resident-sidebar.php'; ?>
<div class="modal active" id="adoptionApplicationDialog">
  <div class="sd-dialog">
  <section class="sd-dialog-content" role="dialog" aria-modal="true" aria-labelledby="application-title" aria-describedby="application-description">
    <button type="button" class="sd-dialog-close" aria-label="Close application" onclick="window.location.href='dashboard-resident.php#adoption'">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
    </button>
    <header class="sd-dialog-header">
      <h1 class="sd-dialog-title" id="application-title">Adoption Application</h1>
      <p class="sd-dialog-description" id="application-description">Complete all required fields to submit your application for staff review.</p>
    </header>
    <div class="application-body">
      <?php if (!$selectedPet): ?>
        <div class="application-notice error" style="display:block">This pet is no longer available for applications.</div>
        <a class="sd-btn sd-btn-outline" href="dashboard-resident.php#adoption" style="text-decoration:none">Return to Adoption Listings</a>
      <?php else: ?>
        <div class="pet-summary">
          <?php if (!empty($selectedPet['photo_url'])): ?><img src="<?php echo htmlspecialchars($selectedPet['photo_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($selectedPet['name'], ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
          <div><strong><?php echo htmlspecialchars($selectedPet['name'], ENT_QUOTES, 'UTF-8'); ?></strong><p><?php echo htmlspecialchars(ucfirst($selectedPet['species']) . ' · ' . $selectedPet['breed'] . ' · ' . $selectedPet['age'], ENT_QUOTES, 'UTF-8'); ?></p></div>
        </div>
        <div class="qualification">
          <h2>System pre-qualification checks</h2>
          <div class="check-grid">
            <?php foreach ($checks as $label => $passed): ?>
              <div class="check-item<?php echo $passed ? '' : ' failed'; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="application-notice" id="application-notice" role="status"></div>
        <?php if (!$canApply): ?>
          <p class="application-notice error" style="display:block">You can still review the application form, but it cannot be submitted until all pre-qualification checks pass.</p>
        <?php endif; ?>
        <form id="adoption-application-form" method="post" enctype="multipart/form-data" action="adoption-workflow-handler.php">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="submit_application">
          <input type="hidden" name="listing_id" value="<?php echo (int)$selectedPet['listing_id']; ?>">
          <section class="application-section">
            <h2>1 &nbsp; Personal information</h2>
            <div class="application-grid">
              <div class="application-field"><label>Full name</label><input type="text" value="<?php echo htmlspecialchars($resident['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
              <div class="application-field"><label for="date_of_birth">Date of birth (18+)<span class="required-mark" aria-hidden="true">*</span></label><input id="date_of_birth" name="date_of_birth" type="date" required max="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($resident['birthdate'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><small>Applicants must be 18 years or older.</small></div>
              <div class="application-field"><label>Contact number</label><input type="tel" value="<?php echo htmlspecialchars($resident['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
              <div class="application-field"><label>Email address</label><input type="email" value="<?php echo htmlspecialchars($resident['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
              <div class="application-field"><label>Barangay (Dasmariñas City)</label><input type="text" value="<?php echo htmlspecialchars($resident['barangay_name'] ?? 'Not set in your profile', ENT_QUOTES, 'UTF-8'); ?>" readonly></div>
              <div class="application-field"><label for="residency_proof">Proof of residency<span class="required-mark" aria-hidden="true">*</span></label><input class="upload-field" id="residency_proof" name="residency_proof" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" required><small>Barangay certificate, utility bill, or lease agreement; max 10 MB.</small></div>
            </div>
          </section>
          <section class="application-section">
            <h2>2 &nbsp; Housing &amp; living situation</h2>
            <div class="application-grid">
              <div class="application-field"><label for="housing_type">Housing<span class="required-mark" aria-hidden="true">*</span></label><select id="housing_type" name="housing_type" required><option value="">Select</option><option value="owned">Owned</option><option value="rented">Rented</option></select></div>
              <div class="application-field" id="landlord-permission-field" hidden><label><input type="checkbox" name="landlord_permission" value="1"> I have landlord permission.<span class="required-mark" aria-hidden="true">*</span></label></div>
              <div class="application-field"><label for="home_type">Type of house<span class="required-mark" aria-hidden="true">*</span></label><select id="home_type" name="home_type" required><option value="">Select</option><option>Single-family house</option><option>Apartment</option><option>Condominium</option><option>Townhouse</option><option>Other</option></select></div>
              <div class="application-field"><label for="has_fenced_yard">Yard or fenced area?<span class="required-mark" aria-hidden="true">*</span></label><select id="has_fenced_yard" name="has_fenced_yard" required><option value="">Select</option><option value="1">Yes</option><option value="0">No</option></select></div>
              <div class="application-field"><label for="household_members">Members in household<span class="required-mark" aria-hidden="true">*</span></label><input id="household_members" name="household_members" type="number" min="1" max="50" placeholder="e.g., 4" required><small>Including yourself.</small></div>
            </div>
          </section>
          <section class="application-section">
            <h2>3 &nbsp; Pet experience</h2>
            <div class="application-grid">
              <div class="application-field"><label for="has_other_pets">Do you currently have other pets?<span class="required-mark" aria-hidden="true">*</span></label><select id="has_other_pets" name="has_other_pets" required><option value="">Select</option><option value="0">No</option><option value="1">Yes</option></select></div>
              <div class="application-field"><label for="pet_care_experience">Prior pet-care experience<span class="required-mark" aria-hidden="true">*</span></label><select id="pet_care_experience" name="pet_care_experience" required><option value="">Select</option><option value="No prior experience">No prior experience</option><option value="Less than 1 year">Less than 1 year</option><option value="1-3 years">1-3 years</option><option value="4+ years">4+ years</option></select></div>
              <div class="application-field full" id="other-pets-field" hidden><label for="other_pets_details">Describe your other pets<span class="required-mark" aria-hidden="true">*</span></label><textarea id="other_pets_details" name="other_pets_details"></textarea></div>
              <div class="application-field full"><label for="reason_for_adoption">Why do you want to adopt this pet?<span class="required-mark" aria-hidden="true">*</span></label><textarea id="reason_for_adoption" name="reason_for_adoption" maxlength="5000" required placeholder="Tell us about your motivation for adopting and how you plan to care for this pet..."></textarea></div>
            </div>
          </section>
          <section class="application-section" style="border-bottom:0;margin-bottom:0;padding-bottom:0">
            <h2>4 &nbsp; Adoption agreement</h2>
            <div class="agreement-box">
              <strong>I agree to the following conditions:</strong>
              <label><input type="checkbox" name="agreement_vaccination" value="1" required> I will ensure timely vaccination and annual veterinary check-ups for the adopted pet.<span class="required-mark" aria-hidden="true">*</span></label>
              <label><input type="checkbox" name="agreement_spay_neuter" value="1" required> I will have the pet spayed/neutered when appropriate and follow the DCVS schedule.<span class="required-mark" aria-hidden="true">*</span></label>
              <label><input type="checkbox" name="agreement_no_transfer" value="1" required> I will not sell, trade, give away, or abandon this pet. If I can no longer care for it, I will return it to DCVS.<span class="required-mark" aria-hidden="true">*</span></label>
              <label><input type="checkbox" name="agreement_followup" value="1" required> I consent to follow-up welfare checks and/or home visits by DCVS staff.<span class="required-mark" aria-hidden="true">*</span></label>
            </div>
          </section>
        </form>
      <?php endif; ?>
    </div>
    <?php if ($selectedPet): ?>
    <footer class="sd-dialog-footer">
      <p class="application-footer-note">Your application will be reviewed by DCVS staff. <?php echo htmlspecialchars($selectedPet['name'], ENT_QUOTES, 'UTF-8'); ?> will be reserved during review.</p>
      <button class="sd-btn sd-btn-outline" type="button" onclick="window.location.href='dashboard-resident.php#adoption'">Cancel</button>
      <button class="sd-btn sd-btn-default" type="submit" form="adoption-application-form" id="submit-application-button" <?php echo $canApply ? '' : 'disabled'; ?>><i class="fas fa-paper-plane"></i> Submit Application</button>
    </footer>
    <?php endif; ?>
  </section>
  </div>
</div>
<script>
(function () {
  var form = document.getElementById('adoption-application-form');
  var notice = document.getElementById('application-notice');
  var housing = document.getElementById('housing_type');
  var landlordField = document.getElementById('landlord-permission-field');
  var otherPets = document.getElementById('has_other_pets');
  var otherPetsField = document.getElementById('other-pets-field');
  var canApply = <?php echo $canApply ? 'true' : 'false'; ?>;

  if (housing && landlordField) {
    housing.addEventListener('change', function () {
      landlordField.hidden = housing.value !== 'rented';
      var permission = landlordField.querySelector('input');
      permission.required = housing.value === 'rented';
      if (housing.value !== 'rented') permission.checked = false;
    });
  }
  if (otherPets && otherPetsField) {
    otherPets.addEventListener('change', function () {
      otherPetsField.hidden = otherPets.value !== '1';
      var details = otherPetsField.querySelector('textarea');
      details.required = otherPets.value === '1';
      if (otherPets.value !== '1') details.value = '';
    });
  }
  if (!form) return;
  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (!canApply) {
      notice.style.display = 'block';
      notice.className = 'application-notice error';
      notice.textContent = 'Your application cannot be submitted until all system pre-qualification checks pass.';
      return;
    }
    notice.style.display = 'block';
    notice.className = 'application-notice';
    notice.textContent = 'Submitting your application...';
    var button = document.getElementById('submit-application-button');
    button.disabled = true;
    try {
      var response = await fetch(form.getAttribute('action'), {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      var responseText = await response.text();
      var result;
      try {
        result = JSON.parse(responseText);
      } catch (parseError) {
        notice.classList.add('error');
        if (response.redirected) {
          notice.textContent = 'Your resident session may have expired. Sign in again, then reopen the pet application.';
        } else {
          notice.textContent = 'The server returned an unreadable response (HTTP ' + response.status + '). Refresh the page and try again.';
        }
        button.disabled = false;
        return;
      }
      notice.textContent = result.message || (result.success ? 'Application submitted.' : 'Application could not be submitted.');
      if (result.success) {
        notice.style.display = 'block';
        window.setTimeout(function () { window.location.href = 'dashboard-resident.php#adoption'; }, 900);
      } else {
        notice.classList.add('error');
        button.disabled = false;
      }
    } catch (error) {
      notice.classList.add('error');
      notice.textContent = 'Could not reach the DCVS application handler. Check that the portal is available, then try again.';
      button.disabled = false;
    }
  });
})();
</script>
</body>
</html>
