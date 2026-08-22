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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['register_error'] = 'Invalid form submission. Please try again.';
        header('Location: register.php');
        exit;
    }
    // Collect and sanitize form data
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $birthdate = $_POST['birthdate'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $street = trim($_POST['street'] ?? '');
    $barangay = $_POST['barangay'] ?? '';
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

    // If there are errors, redirect back with error message
    if (!empty($errors)) {
        $_SESSION['register_error'] = implode('<br>', $errors);
        header('Location: register.php');
        exit;
    }

    // Check if email already exists
    $checkEmail = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    $checkEmail->store_result();

    if ($checkEmail->num_rows > 0) {
        $_SESSION['register_error'] = "Email address is already registered.";
        header('Location: register.php');
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
        header('Location: register.php');
        exit;
    }
    $checkUsername->close();

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Combine address
    $fullAddress = $street . ', ' . $barangay . ', Dasmariñas, Cavite';

    // Get barangay_id from barangays table (or create if not exists)
    $barangayId = null;
    $checkBarangay = $conn->prepare("SELECT barangay_id FROM barangays WHERE barangay_name = ?");
    $checkBarangay->bind_param("s", $barangay);
    $checkBarangay->execute();
    $checkBarangay->store_result();
    
    if ($checkBarangay->num_rows > 0) {
        $checkBarangay->bind_result($barangayId);
        $checkBarangay->fetch();
    }
    $checkBarangay->close();

    // Insert user into database
    $refId = generateReferenceId($conn, '#RES-', 'users');
    $stmt = $conn->prepare("INSERT INTO users (username, password, email, full_name, role, barangay_id, phone, address, status, reference_id, terms_agreed, terms_agreed_at) VALUES (?, ?, ?, ?, 'resident', ?, ?, ?, 'active', ?, 1, NOW())");
    $fullName = $firstName . ' ' . $lastName;
    $stmt->bind_param("ssssisss", $username, $hashedPassword, $email, $fullName, $barangayId, $phone, $fullAddress, $refId);

    if ($stmt->execute()) {
        $_SESSION['register_success'] = "Account created successfully! You can now log in.";
        header('Location: login.php');
        exit;
    } else {
        $_SESSION['register_error'] = "Registration failed. Please try again.";
        header('Location: register.php');
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
  <meta name="description" content="Create your Dasmariñas City Veterinary Services account to register pets, submit reports, and request services." />
  <title>Register — Dasmariñas City Veterinary Services</title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />

  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />

  <!-- Styles -->
  <link rel="stylesheet" href="assets/css/auth.css" />
  <link rel="stylesheet" href="assets/css/responsive.css" />
</head>
<body class="auth-body">

  <!-- Background subtle accents -->
  <div class="bg-blob blob-1"></div>
  <div class="bg-blob blob-2"></div>

  <div class="auth-wrapper auth-wrapper-register">

    <!-- Left Panel: Branding (same as login) -->
    <div class="auth-brand-panel">
      <div class="brand-content">
        <img src="uploads/site/logo.png" alt="DCVS Logo" class="auth-logo" />
        <h1 class="brand-title">Join DCVS<br />Animal Welfare System</h1>
        <p class="brand-subtitle">Create your account and become part of Dasmariñas City's animal welfare community</p>

        <div class="register-highlights">
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>Free to register as a Resident</span>
          </div>
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>Register and track your pets</span>
          </div>
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>Submit stray &amp; rabies reports</span>
          </div>
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>Book appointments online</span>
          </div>
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>Browse adoption listings</span>
          </div>
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>View live geo-mapped alerts</span>
          </div>
        </div>

        <div class="brand-note">
          <i class="fas fa-info-circle"></i>
          <span>Barangay Officers and DCVS Staff accounts are managed by the system administrator.</span>
        </div>
      </div>
    </div>

    <!-- Right Panel: Register Form -->
    <div class="auth-form-panel auth-form-panel-register">
      <div class="auth-form-card auth-form-card-register">

        <div class="form-header">
          <h2>Create Account</h2>
          <p>Fill in your details below to register as a Resident</p>
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

        <!-- Alert Message (hidden by default) -->
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

        <form id="registerForm" class="auth-form" action="register.php" method="POST" novalidate>
          <?php echo csrfField(); ?>

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
                  <option value="Burol">Burol</option>
                  <option value="Burol I">Burol I</option>
                  <option value="Burol II">Burol II</option>
                  <option value="Burol III">Burol III</option>
                  <option value="Datu Esmael">Datu Esmael</option>
                  <option value="Emmanuel Pelaez">Emmanuel Pelaez</option>
                  <option value="Fatima I">Fatima I</option>
                  <option value="Fatima II">Fatima II</option>
                  <option value="Fatima III">Fatima III</option>
                  <option value="H-95">H-95</option>
                  <option value="Langkaan I">Langkaan I</option>
                  <option value="Langkaan II">Langkaan II</option>
                  <option value="Lumina I">Lumina I</option>
                  <option value="Lumina II">Lumina II</option>
                  <option value="Magdalo">Magdalo</option>
                  <option value="Maliksi I">Maliksi I</option>
                  <option value="Maliksi II">Maliksi II</option>
                  <option value="Maliksi III">Maliksi III</option>
                  <option value="Maunlad I">Maunlad I</option>
                  <option value="Maunlad II">Maunlad II</option>
                  <option value="Maunlad III">Maunlad III</option>
                  <option value="Paliparan I">Paliparan I</option>
                  <option value="Paliparan II">Paliparan II</option>
                  <option value="Paliparan III">Paliparan III</option>
                  <option value="Pasong Camachile I">Pasong Camachile I</option>
                  <option value="Pasong Camachile II">Pasong Camachile II</option>
                  <option value="Pasong Kawayan I">Pasong Kawayan I</option>
                  <option value="Pasong Kawayan II">Pasong Kawayan II</option>
                  <option value="Sabang">Sabang</option>
                  <option value="Salawag">Salawag</option>
                  <option value="Salitran I">Salitran I</option>
                  <option value="Salitran II">Salitran II</option>
                  <option value="Salitran III">Salitran III</option>
                  <option value="Salitran IV">Salitran IV</option>
                  <option value="Sampaloc I">Sampaloc I</option>
                  <option value="Sampaloc II">Sampaloc II</option>
                  <option value="Sampaloc III">Sampaloc III</option>
                  <option value="Sampaloc IV">Sampaloc IV</option>
                  <option value="San Agustin I">San Agustin I</option>
                  <option value="San Agustin II">San Agustin II</option>
                  <option value="San Agustin III">San Agustin III</option>
                  <option value="San Andres I">San Andres I</option>
                  <option value="San Andres II">San Andres II</option>
                  <option value="San Isidro Labrador I">San Isidro Labrador I</option>
                  <option value="San Isidro Labrador II">San Isidro Labrador II</option>
                  <option value="San Jose">San Jose</option>
                  <option value="San Juan I">San Juan I</option>
                  <option value="San Juan II">San Juan II</option>
                  <option value="San Lorenzo Ruiz I">San Lorenzo Ruiz I</option>
                  <option value="San Lorenzo Ruiz II">San Lorenzo Ruiz II</option>
                  <option value="San Miguel">San Miguel</option>
                  <option value="San Simon">San Simon</option>
                  <option value="Santa Cruz I">Santa Cruz I</option>
                  <option value="Santa Cruz II">Santa Cruz II</option>
                  <option value="Santa Fe I">Santa Fe I</option>
                  <option value="Santa Fe II">Santa Fe II</option>
                  <option value="Santo Cristo I">Santo Cristo I</option>
                  <option value="Santo Cristo II">Santo Cristo II</option>
                  <option value="Santo Niño I">Santo Niño I</option>
                  <option value="Santo Niño II">Santo Niño II</option>
                  <option value="Zone I (Pob.)">Zone I (Pob.)</option>
                  <option value="Zone II (Pob.)">Zone II (Pob.)</option>
                  <option value="Zone III (Pob.)">Zone III (Pob.)</option>
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
              <!-- Password Strength Bar -->
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
          <a href="login.php" class="switch-link">Sign in here <i class="fas fa-arrow-right"></i></a>
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
</body>
</html>
