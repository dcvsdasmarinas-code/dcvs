<?php
session_name('DCVS_ADMIN_SESSION');
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
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'admin') {
    header('Location: login-admin.php');
    exit;
}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/reports/location.php';

$officialBarangays = dcvsLoadApprovedBarangays($conn);
$officialBarangaysByCode = [];
foreach ($officialBarangays as $officialBarangay) {
    $officialBarangaysByCode[$officialBarangay['psgc_code']] = $officialBarangay;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize form data
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $birthdate = $_POST['birthdate'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $street = trim($_POST['street'] ?? '');
    $barangayCode = trim($_POST['barangay'] ?? '');
    $barangay = $officialBarangaysByCode[$barangayCode]['barangay_name'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $agreeTerms = isset($_POST['agree_terms']);

    // Server-side validation
    $errors = [];

    $username = trim($_POST['username'] ?? '');

    if (empty($username)) {
        $errors[] = "Username is required.";
    } elseif (strlen($username) < 3) {
        $errors[] = "Username must be at least 3 characters.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = "Username can only contain letters, numbers, and underscores.";
    }

    if (empty($firstName)) {
        $errors[] = "First name is required.";
    }
    if (empty($lastName)) {
        $errors[] = "Last name is required.";
    }
    if (empty($phone)) {
        $errors[] = "Phone number is required.";
    } elseif (!preg_match('/^(\+63|0)[0-9]{10}$/', preg_replace('/\s/', '', $phone))) {
        $errors[] = "Enter a valid PH number (e.g. 09XX XXX XXXX).";
    }
    if (empty($birthdate)) {
        $errors[] = "Date of birth is required.";
    } else {
        $dob = new DateTime($birthdate);
        $today = new DateTime();
        $age = $today->diff($dob)->y;
        if ($age < 18) {
            $errors[] = "You must be at least 18 years old to register.";
        }
    }
    if (empty($gender)) {
        $errors[] = "Please select your gender.";
    }
    if (empty($street)) {
        $errors[] = "Street address is required.";
    }
    if (empty($barangay)) {
        $errors[] = "Please select your barangay.";
    }
    if (empty($email)) {
        $errors[] = "Email address is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters.";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter.";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number.";
    } elseif (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Password must contain at least one special character.";
    }
    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }
    if (!$agreeTerms) {
        $errors[] = "You must agree to the Terms and Conditions.";
    }

    if (!empty($errors)) {
        $_SESSION['register_error'] = implode('<br>', $errors);
        header('Location: register-staff.php');
        exit;
    }

    // Check if email already exists
    $checkEmail = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    $checkEmail->store_result();
    if ($checkEmail->num_rows > 0) {
        $_SESSION['register_error'] = "Email address is already registered.";
        header('Location: register-staff.php');
        exit;
    }
    $checkEmail->close();

    // Check if username already exists
    $checkUsername = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
    $checkUsername->bind_param("s", $username);
    $checkUsername->execute();
    $checkUsername->store_result();
    if ($checkUsername->num_rows > 0) {
        $_SESSION['register_error'] = "Username is already taken. Please choose another.";
        header('Location: register-staff.php');
        exit;
    }
    $checkUsername->close();

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $fullAddress = $street . ', ' . $barangay . ', Dasmariñas, Cavite';

    $barangayId = (int)$officialBarangaysByCode[$barangayCode]['barangay_id'];

    $refId = generateReferenceId($conn, '#STF-', 'users');
    $fullName = $firstName . ' ' . $lastName;
    $stmt = $conn->prepare("INSERT INTO users (username, password, email, full_name, first_name, last_name, role, barangay_id, phone, birthdate, gender, address, status, reference_id) VALUES (?, ?, ?, ?, ?, ?, 'staff', ?, ?, ?, ?, ?, 'active', ?)");
    $stmt->bind_param("ssssssisssss", $username, $hashedPassword, $email, $fullName, $firstName, $lastName, $barangayId, $phone, $birthdate, $gender, $fullAddress, $refId);

    if ($stmt->execute()) {
        $new_user_id = $stmt->insert_id;
        $action = "Account Created";
        $details = "Staff account registered.";
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $new_user_id, $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        $_SESSION['register_success'] = "Staff account created successfully! You can now log in.";
        header('Location: login-staff.php');
        exit;
    } else {
        $_SESSION['register_error'] = "Registration failed. Please try again.";
        header('Location: register-staff.php');
        exit;
    }
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Staff Registration — Dasmariñas City Veterinary Services</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/css/auth.css" />
  <link rel="stylesheet" href="assets/css/responsive.css" />
</head>
<body class="auth-body">

  <div class="bg-blob blob-1"></div>
  <div class="bg-blob blob-2"></div>
  <div class="bg-blob blob-3"></div>

  <div class="auth-wrapper auth-wrapper-register">

    <!-- Left Panel: Branding -->
    <div class="auth-brand-panel">
      <div class="brand-content">
        <img src="uploads/site/DCVS LOGO.png" alt="DCVS Logo" class="auth-logo" />
        <h1 class="brand-title">Join DCVS<br />Staff Management</h1>
        <p class="brand-subtitle">Create your staff account and help manage the city's animal welfare programs.</p>

        <div class="register-highlights">
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>Free to register as Staff</span>
          </div>
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>Manage pet registrations</span>
          </div>
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>Process appointments &amp; reports</span>
          </div>
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>Coordinate with barangay officials</span>
          </div>
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>Access system analytics</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Right Panel: Register Form -->
    <div class="auth-form-panel auth-form-panel-register">
      <div class="auth-form-card auth-form-card-register">

        <div class="form-header">
          <h2>Create Account</h2>
          <p>Fill in your details below to register as a Staff</p>
        </div>

        <!-- Steps Indicator -->
        <div class="steps-indicator">
          <div class="step-dot active" data-step="1">
            <span class="dot-num">1</span>
            <span class="dot-label">Personal</span>
          </div>
          <div class="step-line"></div>
          <div class="step-dot" data-step="2">
            <span class="dot-num">2</span>
            <span class="dot-label">Address</span>
          </div>
          <div class="step-line"></div>
          <div class="step-dot" data-step="3">
            <span class="dot-num">3</span>
            <span class="dot-label">Account</span>
          </div>
        </div>

        <?php if (isset($_SESSION['register_error'])): ?>
          <div class="auth-alert auth-alert-error" id="registerAlert">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($_SESSION['register_error']); unset($_SESSION['register_error']); ?></span>
          </div>
        <?php else: ?>
          <div class="auth-alert auth-alert-error" id="registerAlert" style="display:none;">
            <i class="fas fa-exclamation-circle"></i>
            <span id="registerAlertMsg">Please fix the errors below.</span>
          </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['register_success'])): ?>
          <div class="auth-alert auth-alert-success" id="registerSuccess">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($_SESSION['register_success']); unset($_SESSION['register_success']); ?></span>
          </div>
        <?php else: ?>
          <div class="auth-alert auth-alert-success" id="registerSuccess" style="display:none;">
            <i class="fas fa-check-circle"></i>
            <span>Account created successfully! Redirecting to login...</span>
          </div>
        <?php endif; ?>

        <form id="registerForm" class="auth-form" action="register-staff.php" method="POST" novalidate>

          <!-- ===== STEP 1: Personal Info ===== -->
          <div class="form-step active" id="step1">
            <div class="form-row">
              <div class="form-group" id="firstNameGroup">
                <label for="firstName">First Name <span class="required">*</span></label>
                <div class="input-wrapper">
                  <i class="fas fa-user input-icon"></i>
                  <input type="text" id="firstName" name="first_name" placeholder="Juan" required />
                </div>
                <span class="field-error" id="firstNameError"></span>
              </div>
              <div class="form-group" id="lastNameGroup">
                <label for="lastName">Last Name <span class="required">*</span></label>
                <div class="input-wrapper">
                  <i class="fas fa-user input-icon"></i>
                  <input type="text" id="lastName" name="last_name" placeholder="Dela Cruz" required />
                </div>
                <span class="field-error" id="lastNameError"></span>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group" id="phoneGroup">
                <label for="phone">Phone Number <span class="required">*</span></label>
                <div class="input-wrapper">
                  <i class="fas fa-phone input-icon"></i>
                  <input type="tel" id="phone" name="phone" placeholder="+63 9XX XXX XXXX" required />
                </div>
                <span class="field-error" id="phoneError"></span>
              </div>
              <div class="form-group" id="birthdateGroup">
                <label for="birthdate">Date of Birth <span class="required">*</span></label>
                <div class="input-wrapper">
                  <i class="fas fa-calendar input-icon"></i>
                  <input type="date" id="birthdate" name="birthdate" required />
                </div>
                <span class="field-error" id="birthdateError"></span>
              </div>
            </div>
            <div class="form-group" id="genderGroup">
              <label for="gender">Gender <span class="required">*</span></label>
              <div class="input-wrapper">
                <i class="fas fa-venus-mars input-icon"></i>
                <select id="gender" name="gender" required>
                  <option value="">Select Gender</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                  <option value="prefer_not">Prefer not to say</option>
                </select>
              </div>
              <span class="field-error" id="genderError"></span>
            </div>
            <div class="step-nav">
              <div></div>
              <button type="button" class="btn-auth btn-next" id="nextToStep2">
                Next <i class="fas fa-arrow-right"></i>
              </button>
            </div>
          </div>

          <!-- ===== STEP 2: Address ===== -->
          <div class="form-step" id="step2">
            <div class="form-group" id="streetGroup">
              <label for="street">Street Address <span class="required">*</span></label>
              <div class="input-wrapper">
                <i class="fas fa-home input-icon"></i>
                <input type="text" id="street" name="street" placeholder="123 Maharlika Street" required />
              </div>
              <span class="field-error" id="streetError"></span>
            </div>
            <div class="form-group" id="barangayGroup">
              <label for="barangay">Barangay <span class="required">*</span></label>
              <div class="input-wrapper">
                <i class="fas fa-map-pin input-icon"></i>
                <select id="barangay" name="barangay" required>
                  <option value="">Select Barangay</option>
                  <?php foreach ($officialBarangays as $officialBarangay): ?>
                    <option value="<?php echo htmlspecialchars($officialBarangay['psgc_code'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($officialBarangay['barangay_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <span class="field-error" id="barangayError"></span>
            </div>
            <div class="form-group">
              <label>City / Province</label>
              <div class="input-wrapper input-readonly">
                <i class="fas fa-city input-icon"></i>
                <input type="text" value="Dasmariñas, Cavite" readonly />
              </div>
            </div>
            <div class="step-nav">
              <button type="button" class="btn-auth btn-back btn-secondary" id="backToStep1">
                <i class="fas fa-arrow-left"></i> Back
              </button>
              <button type="button" class="btn-auth btn-next" id="nextToStep3">
                Next <i class="fas fa-arrow-right"></i>
              </button>
            </div>
          </div>

          <!-- ===== STEP 3: Account Credentials ===== -->
          <div class="form-step" id="step3">
            <div class="form-group" id="regUsernameGroup">
              <label for="regUsername">Username <span class="required">*</span></label>
              <div class="input-wrapper">
                <i class="fas fa-user input-icon"></i>
                <input type="text" id="regUsername" name="username" placeholder="Choose a username" required />
              </div>
              <span class="field-error" id="regUsernameError"></span>
            </div>
            <div class="form-group" id="regEmailGroup">
              <label for="regEmail">Email Address <span class="required">*</span></label>
              <div class="input-wrapper">
                <i class="fas fa-envelope input-icon"></i>
                <input type="email" id="regEmail" name="email" placeholder="juan@email.com" required />
              </div>
              <span class="field-error" id="regEmailError"></span>
            </div>
            <div class="form-group" id="regPasswordGroup">
              <label for="regPassword">Password <span class="required">*</span></label>
              <div class="input-wrapper">
                <i class="fas fa-lock input-icon"></i>
                <input type="password" id="regPassword" name="password" placeholder="8+ chars, upper+lower+number+symbol" required />
                <button type="button" class="toggle-password" id="toggleRegPassword" aria-label="Toggle password visibility">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
              <span class="field-error" id="regPasswordError"></span>
              <div class="password-strength" id="passwordStrength" style="display:none;">
                <div class="strength-bar">
                  <div class="strength-fill" id="strengthFill"></div>
                </div>
                <span class="strength-label" id="strengthLabel">Weak</span>
              </div>
            </div>
            <div class="form-group" id="confirmPasswordGroup">
              <label for="confirmPassword">Confirm Password <span class="required">*</span></label>
              <div class="input-wrapper">
                <i class="fas fa-lock input-icon"></i>
                <input type="password" id="confirmPassword" name="confirm_password" placeholder="Re-enter your password" required />
                <button type="button" class="toggle-password" id="toggleConfirmPassword" aria-label="Toggle confirm password visibility">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
              <span class="field-error" id="confirmPasswordError"></span>
            </div>
            <div class="form-group">
              <label class="terms-label">
                <input type="checkbox" name="agree_terms" id="agreeTerms" />
                <span class="checkmark"></span>
                I have read and agree to the <strong><u><a href="#" onclick="event.preventDefault(); openTermsModal();" class="terms-link" style="font-weight:700;text-decoration:underline;">Terms and Conditions</a></u></strong>
              </label>
              <span class="field-error" id="termsError"></span>
            </div>
            <div class="step-nav">
              <button type="button" class="btn-auth btn-back btn-secondary" id="backToStep2">
                <i class="fas fa-arrow-left"></i> Back
              </button>
              <button type="submit" class="btn-auth" id="registerBtn">
                <span class="btn-text">Create Account</span>
                <i class="fas fa-user-plus btn-icon"></i>
                <span class="btn-loader" style="display:none;"><i class="fas fa-spinner fa-spin"></i></span>
              </button>
            </div>
          </div>

        </form>

        <div class="auth-divider">
          <span>or</span>
        </div>

        <p class="auth-switch">
          Already have an account?
          <a href="login-staff.php" class="switch-link">Sign in <i class="fas fa-arrow-right"></i></a>
        </p>

        <div class="back-to-home">
          <a href="landing-page.php"><i class="fas fa-arrow-left"></i> Back to Home</a>
        </div>

      </div>
    </div>
  </div>

  <!-- Terms & Conditions Modal -->
  <div class="modal" id="termsModal">
    <div class="modal-content">
      <div class="modal-header">
        <h3><i class="fas fa-file-contract"></i> Terms and Conditions</h3>
        <button class="modal-close" onclick="closeTermsModal()">&times;</button>
      </div>
      <div class="modal-body">
        <div class="terms-content">
          <h4>1. Acceptance of Terms</h4>
          <p>By creating an account and using Dasmari&ntilde;as CVS, you agree to be bound by these Terms and Conditions. If you do not agree, please do not use the system.</p>
          <h4>2. Responsible Use</h4>
          <p>You agree to use the system only for lawful purposes and in a manner that does not infringe on the rights of others or restrict their use and enjoyment of the system.</p>
          <h4>3. Account Responsibilities</h4>
          <p>You are responsible for maintaining the confidentiality of your login credentials and for all activities that occur under your account. Notify the administrator immediately of any unauthorized use.</p>
          <h4>4. Accuracy of Information</h4>
          <p>You agree to provide accurate, current, and complete information during registration and to update your information as needed to keep it accurate and complete.</p>
          <h4>5. Appointment and Report Policies</h4>
          <p>Appointments and reports submitted through the system are subject to verification and approval by barangay officials. The system reserves the right to reschedule or cancel appointments as necessary.</p>
          <h4>6. Pet Adoption Policies</h4>
          <p>Pet adoption applications are subject to screening and approval. Providing false information may result in immediate disqualification. Adopters must comply with all local ordinances regarding pet ownership.</p>
          <h4>7. Privacy and Data Protection</h4>
          <p>Your personal data will be collected, stored, and processed in accordance with applicable data protection laws. We implement reasonable security measures to protect your information.</p>
          <h4>8. Prohibited Activities</h4>
          <p>You may not use the system to: violate any law or regulation; impersonate any person; transmit harmful code; interfere with system security; or engage in any activity that disrupts system functionality.</p>
          <h4>9. Suspension and Termination</h4>
          <p>We reserve the right to suspend or terminate your account at any time for violation of these terms, without prior notice. Terminated accounts will lose access to all system features.</p>
          <h4>10. Changes to Terms</h4>
          <p>We reserve the right to modify these terms at any time. Continued use of the system after changes constitutes acceptance of the new terms. You will be notified of material changes via email or system notice.</p>
        </div>
      </div>
      <div class="modal-actions">
        <button class="btn-auth btn-secondary" onclick="closeTermsModal()">Cancel</button>
        <button class="btn-auth" id="agreeTermsBtn" style="background:#000;">I Agree</button>
      </div>
    </div>
  </div>

<script src="assets/js/auth.js"></script>
<script>
  function openTermsModal() { var m = document.getElementById('termsModal'); if (m) m.style.display = 'flex'; }
  function closeTermsModal() { var m = document.getElementById('termsModal'); if (m) m.style.display = 'none'; }
  document.addEventListener('DOMContentLoaded', function() {
    var agreeBtn = document.getElementById('agreeTermsBtn');
    var chk = document.getElementById('agreeTerms');
    if (agreeBtn && chk) {
      agreeBtn.addEventListener('click', function() { chk.checked = true; closeTermsModal(); });
    }
  });

  var currentStep = 1;
  function goToStep(step) {
    document.querySelectorAll('.form-step').forEach(function(s) { s.classList.remove('active'); });
    var el = document.getElementById('step' + step);
    if (el) el.classList.add('active');
    currentStep = step;
  }

  function validateStep1() {
    var valid = true;
    ['firstName','lastName','phone','birthdate','gender'].forEach(function(f) {
      var inp = document.getElementById(f);
      var err = document.getElementById(f + 'Error');
      var grp = document.getElementById(f + 'Group');
      if (err) err.textContent = '';
      if (grp) grp.classList.remove('has-error');
      if (inp && !inp.value.trim()) {
        if (err) err.textContent = 'This field is required.';
        if (grp) grp.classList.add('has-error');
        valid = false;
      }
      if (f === 'phone' && inp && inp.value.trim() && !/^(\+63|0)[0-9]{10}$/.test(inp.value.replace(/\s/g,''))) {
        if (err) err.textContent = 'Enter a valid PH number.';
        if (grp) grp.classList.add('has-error');
        valid = false;
      }
    });
    return valid;
  }

  function validateStep2() {
    var valid = true;
    ['street','barangay'].forEach(function(f) {
      var inp = document.getElementById(f);
      var err = document.getElementById(f + 'Error');
      var grp = document.getElementById(f + 'Group');
      if (err) err.textContent = '';
      if (grp) grp.classList.remove('has-error');
      if (inp && !inp.value.trim()) {
        if (err) err.textContent = 'This field is required.';
        if (grp) grp.classList.add('has-error');
        valid = false;
      }
    });
    return valid;
  }

  document.getElementById('nextToStep2')?.addEventListener('click', function() { if (validateStep1()) goToStep(2); });
  document.getElementById('nextToStep3')?.addEventListener('click', function() { if (validateStep2()) goToStep(3); });
  document.getElementById('backToStep1')?.addEventListener('click', function() { goToStep(1); });
  document.getElementById('backToStep2')?.addEventListener('click', function() { goToStep(2); });

  function setupToggle(toggleId, inputId) {
    var toggle = document.getElementById(toggleId);
    var input = document.getElementById(inputId);
    if (!toggle || !input) return;
    toggle.addEventListener('click', function() {
      var hidden = input.type === 'password';
      input.type = hidden ? 'text' : 'password';
      toggle.querySelector('i').className = hidden ? 'fas fa-eye-slash' : 'fas fa-eye';
    });
  }
  setupToggle('toggleRegPassword', 'regPassword');
  setupToggle('toggleConfirmPassword', 'confirmPassword');

  var pwInput = document.getElementById('regPassword');
  var pwStrength = document.getElementById('passwordStrength');
  var strengthFill = document.getElementById('strengthFill');
  var strengthLabel = document.getElementById('strengthLabel');
  if (pwInput) {
    pwInput.addEventListener('input', function() {
      var val = pwInput.value;
      if (!val) { if (pwStrength) pwStrength.style.display = 'none'; return; }
      if (pwStrength) pwStrength.style.display = 'flex';
      var score = 0;
      if (val.length >= 8) score++;
      if (/[A-Z]/.test(val)) score++;
      if (/[a-z]/.test(val)) score++;
      if (/[0-9]/.test(val)) score++;
      if (/[^A-Za-z0-9]/.test(val)) score++;
      var levels = ['', 'weak', 'weak', 'fair', 'good', 'strong'];
      var labels = ['', 'Weak', 'Weak', 'Fair', 'Good', 'Strong'];
      var colors = ['', '#ef4444', '#ef4444', '#f97316', '#eab308', '#16a34a'];
      if (strengthFill) { strengthFill.className = 'strength-fill ' + levels[score]; strengthFill.style.background = colors[score]; }
      if (strengthLabel) { strengthLabel.textContent = labels[score]; strengthLabel.style.color = colors[score]; }
    });
  }

  var regForm = document.getElementById('registerForm');
  if (regForm) {
    regForm.addEventListener('submit', function(e) {
      var valid = true;
      ['regUsername','regEmail','regPassword','confirmPassword'].forEach(function(f) {
        var inp = document.getElementById(f);
        var err = document.getElementById(f + 'Error');
        var grp = document.getElementById(f + 'Group');
        if (err) err.textContent = '';
        if (grp) grp.classList.remove('has-error');
        if (inp && !inp.value.trim()) {
          if (err) err.textContent = 'This field is required.';
          if (grp) grp.classList.add('has-error');
          valid = false;
        }
      });
      if (!document.getElementById('agreeTerms')?.checked) {
        var termsErr = document.getElementById('termsError');
        if (termsErr) termsErr.textContent = 'You must agree to the Terms and Conditions.';
        valid = false;
      }
      if (document.getElementById('regPassword')?.value !== document.getElementById('confirmPassword')?.value) {
        var err = document.getElementById('confirmPasswordError');
        if (err) err.textContent = 'Passwords do not match.';
        document.getElementById('confirmPasswordGroup')?.classList.add('has-error');
        valid = false;
      }
      var pw = document.getElementById('regPassword')?.value;
      if (pw && pw.length < 8) {
        var pwErr = document.getElementById('regPasswordError');
        if (pwErr) pwErr.textContent = 'Password must be at least 8 characters.';
        document.getElementById('regPasswordGroup')?.classList.add('has-error');
        valid = false;
      } else if (pw && !/[A-Z]/.test(pw)) {
        var pwErr = document.getElementById('regPasswordError');
        if (pwErr) pwErr.textContent = 'Password must contain at least one uppercase letter.';
        document.getElementById('regPasswordGroup')?.classList.add('has-error');
        valid = false;
      } else if (pw && !/[a-z]/.test(pw)) {
        var pwErr = document.getElementById('regPasswordError');
        if (pwErr) pwErr.textContent = 'Password must contain at least one lowercase letter.';
        document.getElementById('regPasswordGroup')?.classList.add('has-error');
        valid = false;
      } else if (pw && !/[0-9]/.test(pw)) {
        var pwErr = document.getElementById('regPasswordError');
        if (pwErr) pwErr.textContent = 'Password must contain at least one number.';
        document.getElementById('regPasswordGroup')?.classList.add('has-error');
        valid = false;
      } else if (pw && !/[^A-Za-z0-9]/.test(pw)) {
        var pwErr = document.getElementById('regPasswordError');
        if (pwErr) pwErr.textContent = 'Password must contain at least one special character.';
        document.getElementById('regPasswordGroup')?.classList.add('has-error');
        valid = false;
      }
      if (!valid) { e.preventDefault(); return; }
    });
  }
</script>
</body>
</html>