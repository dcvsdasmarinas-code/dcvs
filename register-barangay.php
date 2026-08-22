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

// Fetch barangay list for the dropdown
$barangays = [];
$bq = $conn->query("SELECT barangay_id, barangay_name FROM barangays ORDER BY barangay_name ASC");
if ($bq) {
    while ($row = $bq->fetch_assoc()) $barangays[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['register_error'] = 'Invalid form submission. Please try again.';
        header('Location: register-barangay.php');
        exit;
    }
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $barangayId = intval($_POST['barangay_id'] ?? 0);
    $agreeTerms = isset($_POST['agree_terms']);

    $errors = [];

    if (empty($fullName)) $errors[] = "Full name is required.";
    if (empty($phone)) $errors[] = "Phone number is required.";
    elseif (!preg_match('/^(\+63|0)[0-9]{10}$/', preg_replace('/\s/', '', $phone)))
        $errors[] = "Enter a valid PH number (e.g. 09XX XXX XXXX).";
    if (empty($email)) $errors[] = "Email address is required.";
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Please enter a valid email address.";
    if (empty($username)) $errors[] = "Username is required.";
    elseif (strlen($username) < 3) $errors[] = "Username must be at least 3 characters.";
    if (empty($password)) $errors[] = "Password is required.";
    elseif (strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";
    elseif (!preg_match('/[A-Z]/', $password)) $errors[] = "Password must contain at least one uppercase letter.";
    elseif (!preg_match('/[a-z]/', $password)) $errors[] = "Password must contain at least one lowercase letter.";
    elseif (!preg_match('/[0-9]/', $password)) $errors[] = "Password must contain at least one number.";
    elseif (!preg_match('/[^A-Za-z0-9]/', $password)) $errors[] = "Password must contain at least one special character.";
    if ($password !== $confirmPassword) $errors[] = "Passwords do not match.";
    if ($barangayId === 0) $errors[] = "Please select your barangay.";
    if (!$agreeTerms) $errors[] = "You must agree to the Terms and Conditions.";

    if (!empty($errors)) {
        $_SESSION['register_error'] = implode('<br>', $errors);
        header('Location: register-barangay.php');
        exit;
    }

    // Check if email already exists
    $checkEmail = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    $checkEmail->store_result();
    if ($checkEmail->num_rows > 0) {
        $_SESSION['register_error'] = "Email address is already registered.";
        header('Location: register-barangay.php');
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
        header('Location: register-barangay.php');
        exit;
    }
    $checkUsername->close();

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $refId = generateReferenceId($conn, '#BRG-', 'users');
    $stmt = $conn->prepare("INSERT INTO users (username, password, email, full_name, role, barangay_id, phone, status, reference_id, terms_agreed, terms_agreed_at) VALUES (?, ?, ?, ?, 'barangay', ?, ?, 'active', ?, 1, NOW())");
    $stmt->bind_param("ssssiss", $username, $hashedPassword, $email, $fullName, $barangayId, $phone, $refId);

    if ($stmt->execute()) {
        $new_user_id = $stmt->insert_id;
        $action = "Account Created";
        $details = "Barangay account registered.";
        $ip = $_SERVER['REMOTE_ADDR'];
        $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $logStmt->bind_param("isss", $new_user_id, $action, $details, $ip);
        $logStmt->execute();
        $logStmt->close();

        $_SESSION['register_success'] = "Barangay account created successfully! You can now log in.";
        header('Location: login-barangay.php');
        exit;
    } else {
        $_SESSION['register_error'] = "Registration failed. Please try again.";
        header('Location: register-barangay.php');
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
  <title>Barangay Registration — Dasmariñas City Veterinary Services</title>

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

    <div class="auth-brand-panel">
      <div class="brand-content">
        <img src="uploads/site/logo.png" alt="DCVS Logo" class="auth-logo" />
        <h1 class="brand-title">Join DCVS<br />Barangay Network</h1>
        <p class="brand-subtitle">Create your barangay officer account and help keep your community safe.</p>

        <div class="register-highlights">
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>Free to register as Barangay Officer</span>
          </div>
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>Validate resident reports</span>
          </div>
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>Coordinate with DCVS staff</span>
          </div>
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>Track stray &amp; rabies cases</span>
          </div>
          <div class="highlight-item">
            <i class="fas fa-check-circle"></i>
            <span>Schedule case follow-ups</span>
          </div>
        </div>
      </div>
    </div>

    <div class="auth-form-panel auth-form-panel-register">
      <div class="auth-form-card auth-form-card-register">

        <div class="form-header">
          <h2>Create Barangay Account</h2>
          <p>Fill in your details below to register</p>
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
        <?php endif; ?>

        <form id="registerForm" action="register-barangay.php" method="POST" novalidate>
          <?php echo csrfField(); ?>

          <div class="form-group" id="fullNameGroup">
            <label for="fullName">Full Name <span class="required">*</span></label>
            <div class="input-wrapper">
              <i class="fas fa-user input-icon"></i>
              <input type="text" id="fullName" name="full_name" placeholder="Juan Dela Cruz" required />
            </div>
            <span class="field-error" id="fullNameError"></span>
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
            <div class="form-group" id="barangaySelectGroup">
              <label for="barangayId">Barangay <span class="required">*</span></label>
              <div class="input-wrapper">
                <i class="fas fa-map-marker-alt input-icon"></i>
                <select id="barangayId" name="barangay_id" required>
                  <option value="">Select barangay</option>
                  <?php foreach ($barangays as $b): ?>
                    <option value="<?php echo $b['barangay_id']; ?>"><?php echo htmlspecialchars($b['barangay_name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <span class="field-error" id="barangayIdError"></span>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group" id="regUsernameGroup">
              <label for="regUsername">Username <span class="required">*</span></label>
              <div class="input-wrapper">
                <i class="fas fa-at input-icon"></i>
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
          </div>

          <div class="form-row">
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
                <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
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
          </div>

          <div class="form-group">
            <label class="terms-label">
              <input type="checkbox" name="agree_terms" id="agreeTerms" />
              <span class="checkmark"></span>
              I have read and agree to the <strong><u><a href="#" onclick="event.preventDefault(); openTermsModal();" class="terms-link" style="font-weight:700;text-decoration:underline;">Terms and Conditions</a></u></strong>
            </label>
            <span class="field-error" id="termsError"></span>
          </div>

          <button type="submit" class="btn-auth" id="registerBtn" style="margin-top:4px;">
            <span class="btn-text">Create Account</span>
            <i class="fas fa-user-plus btn-icon"></i>
            <span class="btn-loader" style="display:none;"><i class="fas fa-spinner fa-spin"></i></span>
          </button>

        </form>

        <div class="auth-divider">
          <span>or</span>
        </div>

        <p class="auth-switch">
          Already have a barangay account?
          <a href="login-barangay.php" class="switch-link">Sign in here <i class="fas fa-arrow-right"></i></a>
        </p>

        <div class="back-to-home">
          <a href="index.html"><i class="fas fa-arrow-left"></i> Back to Home</a>
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