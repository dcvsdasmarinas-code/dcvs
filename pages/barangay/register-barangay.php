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

require_once __DIR__ . '/../../includes/app-base.php';
$appBaseUrl = dcvsAppBaseUrl();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../includes/reports/location.php';
require_once __DIR__ . '/../../includes/registration-validation.php';

// Fetch barangay list for the dropdown
$barangays = dcvsLoadApprovedBarangays($conn);
$officialBarangayIds = [];
foreach ($barangays as $barangay) {
    $officialBarangayIds[$barangay['barangay_id']] = true;
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
    $phoneError = dcvsRegistrationPhoneError($phone);
    if ($phoneError !== '') $errors[] = $phoneError;
    if (empty($email)) $errors[] = "Email address is required.";
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Please enter a valid email address.";
    if (empty($username)) $errors[] = "Username is required.";
    elseif (strlen($username) < 3) $errors[] = "Username must be at least 3 characters.";
    $passwordError = dcvsRegistrationPasswordError($password);
    if ($passwordError !== '') $errors[] = $passwordError;
    if ($password !== $confirmPassword) $errors[] = "Passwords do not match.";
    if (!isset($officialBarangayIds[$barangayId])) $errors[] = "Please select a valid official barangay.";
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

        $_SESSION['login_notice'] = "Barangay account created successfully. You can now sign in.";
        header('Location: ' . $appBaseUrl . '/login.php');
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
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <style>
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    :root {
      --green-primary: #0a7a3b;
      --green-dark: #065729;
      --green-light: #f2fbf5;
      --text-main: #0f172a;
      --text-muted: #64748b;
      --text-light: #94a3b8;
      --border-color: #e2e8f0;
      --error: #ef4444;
      --error-bg: #fef2f2;
      --surface: #ffffff;
      --bg: #ffffff;
    }

    body {
      font-family: 'Open Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: var(--bg);
      min-height: 100vh;
      -webkit-font-smoothing: antialiased;
      line-height: 1.6;
    }

    /* Split Layout */
    .auth-wrapper {
      display: grid;
      grid-template-columns: 1fr 1fr;
      align-items: start;
      min-height: 100vh;
      width: 100%;
    }

    /* Left Brand Panel */
    .auth-brand-panel {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      position: sticky;
      top: 0;
      height: 100vh;
      padding: 4rem 4rem 2.5rem;
      background: linear-gradient(135deg, var(--green-primary) 0%, var(--green-dark) 100%);
      overflow: hidden;
    }

    .auth-brand-panel::before {
      content: '';
      position: absolute;
      inset: 0;
      background-image: radial-gradient(rgba(255,255,255,0.1) 1px, transparent 1px);
      background-size: 24px 24px;
      opacity: 0.3;
    }

    .brand-content {
      position: relative;
      z-index: 1;
      max-width: 500px;
      width: 100%;
      color: #ffffff;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      flex: 1;
      justify-content: flex-start;
      padding-top: 5vh;
      padding-bottom: 10rem;
    }

    .auth-logo {
      width: 280px;
      height: auto;
      object-fit: contain;
      filter: drop-shadow(0 4px 12px rgba(0,0,0,0.2));
      margin-top: 2rem;
    }

    .brand-title {
      font-family: 'Inter', sans-serif;
      font-size: 3rem;
      font-weight: 600;
      line-height: 1.2;
      color: #ffffff;
      letter-spacing: -0.025em;
      margin-bottom: 0;
    }

    .brand-tagline {
      font-size: 1rem;
      color: rgba(255,255,255,0.8);
      line-height: 1.6;
      margin-top: 1.5rem;
      white-space: nowrap;
    }

    .brand-trust {
      position: absolute;
      left: 0;
      right: 0;
      bottom: 0;
      padding: 0 0 2rem;
      z-index: 1;
      overflow: hidden;
    }

    .brand-logos {
      overflow: hidden;
      position: relative;
      width: 100%;
    }

    .brand-logos-track {
      display: flex;
      align-items: center;
      width: max-content;
      animation: brand-logo-scroll 40s linear infinite;
    }

    @keyframes brand-logo-scroll {
      from { transform: translateX(0); }
      to   { transform: translateX(-50%); }
    }

    .brand-logo-item {
      flex: 0 0 auto;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 2rem;
    }

    .brand-logo-item img {
      height: 80px;
      width: auto;
      max-width: 200px;
      object-fit: contain;
      display: block;
      filter: brightness(0) invert(1) drop-shadow(0 1px 3px rgba(0,0,0,0.2));
    }

    /* Right Form Panel */
    .auth-form-panel {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 2rem clamp(1.5rem, 4vw, 3rem);
      background: var(--bg);
    }

    .auth-form-card {
      width: 100%;
      max-width: 520px;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(12px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .register-header {
      text-align: center;
      margin-bottom: 1.5rem;
    }

    .register-header h2 {
      font-family: 'Inter', sans-serif;
      font-size: 1.4rem;
      font-weight: 700;
      color: var(--text-main);
      margin-bottom: 0.35rem;
      letter-spacing: -0.02em;
    }

    .register-header p {
      font-size: 0.875rem;
      color: var(--text-muted);
    }

    /* Alert */
    .auth-alert {
      display: flex;
      align-items: center;
      gap: 0.65rem;
      padding: 0.5rem 0.75rem;
      border-radius: 6px;
      font-size: 0.8rem;
      font-weight: 500;
      margin-bottom: 0.75rem;
      animation: fadeIn 0.3s ease;
    }
    .auth-alert-error {
      background: var(--error-bg);
      color: var(--error);
      border: 1px solid rgba(239,68,68,0.15);
    }
    .auth-alert-success {
      background: none; border: none; display: block; text-align: center;
      padding: 0; margin: 0 auto 14px; font-size: 13px; font-weight: 400;
      animation: none; color: var(--green-primary);
    }

    /* Form */
    .register-form {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 0.4rem;
    }

    .form-group label {
      font-size: 0.875rem;
      font-weight: 600;
      color: var(--text-main);
    }

    .required { color: var(--error); }

    .input-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }

    .input-wrapper .input-icon {
      position: absolute;
      left: 0.875rem;
      color: var(--text-light);
      font-size: 1rem;
      pointer-events: none;
      transition: color 0.25s;
      z-index: 1;
    }

    .input-wrapper input,
    .input-wrapper select {
      width: 100%;
      padding: 0.55rem 0.875rem 0.55rem 2.6rem;
      border: 1.5px solid var(--border-color);
      border-radius: 6px;
      font-family: 'Open Sans', sans-serif;
      font-size: 0.85rem;
      color: var(--text-main);
      background: var(--surface);
      transition: border-color 0.25s, box-shadow 0.25s;
      outline: none;
      appearance: none;
    }

    .input-wrapper input.password-input {
      padding-right: 2.75rem;
    }

    .input-wrapper select {
      padding-right: 2.5rem;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 0.875rem center;
      cursor: pointer;
    }

    .input-wrapper input::placeholder { color: var(--text-light); }
    .input-wrapper input:hover, .input-wrapper select:hover { border-color: #cbd5e1; }
    .input-wrapper input:focus, .input-wrapper select:focus {
      border-color: var(--green-primary);
      box-shadow: 0 0 0 3px rgba(10,122,59,0.08);
    }
    .input-wrapper input:focus ~ .input-icon,
    .input-wrapper:focus-within .input-icon { color: var(--green-primary); }

    .toggle-password {
      position: absolute;
      right: 0.75rem;
      background: none; border: none; cursor: pointer;
      color: var(--text-light); font-size: 1rem;
      padding: 0.25rem; transition: color 0.2s; z-index: 1;
    }
    .toggle-password:hover { color: var(--green-primary); }

    .field-error {
      font-size: 0.75rem;
      color: var(--error);
      min-height: 0.9rem;
    }

    .form-group.has-error input,
    .form-group.has-error select {
      border-color: var(--error);
      box-shadow: 0 0 0 3px var(--error-bg);
    }
    .form-group.has-error .input-icon { color: var(--error); }

    /* Password Strength */
    .password-strength {
      display: flex; align-items: center; gap: 0.75rem; margin-top: 0.15rem;
    }
    .strength-bar {
      flex: 1; height: 4px; background: var(--border-color);
      border-radius: 2px; overflow: hidden;
    }
    .strength-fill {
      height: 100%; border-radius: 2px; transition: width 0.4s ease, background 0.4s ease; width: 0%;
    }
    .strength-fill.weak { width: 33%; background: var(--error); }
    .strength-fill.medium { width: 66%; background: #f59e0b; }
    .strength-fill.strong { width: 100%; background: #10b981; }
    .strength-label {
      font-size: 0.75rem; font-weight: 600; color: var(--text-muted);
      min-width: 40px; text-align: right;
    }
    .password-requirements { display:grid;gap:0.25rem;margin:0.15rem 0 0;padding:0;list-style:none;color:var(--text-muted);font-size:0.75rem;line-height:1.4; }
    .password-requirements li::before { content:'○';display:inline-block;width:1.15rem;color:var(--text-muted); }
    .password-requirements li.is-met { color:var(--green-primary); }
    .password-requirements li.is-met::before { content:'✓';color:var(--green-primary); }

    /* Terms */
    .terms-label {
      display: flex; align-items: flex-start; gap: 0.5rem;
      font-size: 0.8rem; color: var(--text-muted); cursor: pointer;
      user-select: none; line-height: 1.3;
    }
    .terms-label input[type="checkbox"] {
      width: 16px; height: 16px; accent-color: var(--green-primary);
      cursor: pointer; flex-shrink: 0; margin-top: 0.1rem; border-radius: 3px;
    }
    .terms-link { color: var(--green-primary); font-weight: 700; text-decoration: underline; }
    .terms-link:hover { color: var(--green-dark); }

    /* Button */
    .btn-register {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      width: 100%;
      height: 36px;
      padding: 0 16px;
      border-radius: 6px;
      border: 1px solid transparent;
      background: #18181b;
      color: #fafafa;
      font-family: 'Inter', sans-serif;
      font-size: 14px;
      font-weight: 500;
      line-height: 1;
      white-space: nowrap;
      cursor: pointer;
      box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
      transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
    }
    .btn-register:hover { background: #27272a; }
    .btn-register:active { background: #18181b; box-shadow: none; }
    .btn-register:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }

    .btn-secondary {
      background: var(--surface);
      color: var(--text-main);
      border: 1px solid var(--border-color);
      box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
    }
    .btn-secondary:hover { background: var(--bg); }

    /* Divider */
    .register-divider {
      display: flex; align-items: center; gap: 0.85rem;
      margin: 0.75rem 0; color: var(--text-light);
      font-size: 0.8rem; font-weight: 500;
    }
    .register-divider::before, .register-divider::after {
      content: ''; flex: 1; height: 1px; background: var(--border-color);
    }

    /* Switch */
    .register-switch {
      text-align: center; font-size: 0.875rem; color: var(--text-muted);
    }
    .register-switch a {
      color: var(--green-primary); font-weight: 600; transition: color 0.2s;
      display: inline-flex; align-items: center; gap: 0.3rem;
      margin-left: 0.2rem;
      text-decoration: none;
    }
    .register-switch a:hover { color: var(--green-dark); text-decoration: underline; }
    .register-switch a i { font-size: 0.7rem; transition: transform 0.2s; text-decoration: none; }
    .register-switch a:hover i { transform: translateX(3px); }

    /* Back to Home */
    .back-home {
      text-align: center; margin-top: 0.75rem;
    }
    .back-home a {
      font-size: 0.8rem; color: var(--text-light);
      display: inline-flex; align-items: center; gap: 0.4rem;
      transition: color 0.2s; font-weight: 500;
      text-decoration: none;
    }
    .back-home a:hover { color: var(--text-main); text-decoration: underline; }
    .back-home a i { transition: transform 0.2s; text-decoration: none; }
    .back-home a:hover i { transform: translateX(-3px); }

    /* Terms Modal */
    .modal {
      display: none; position: fixed; inset: 0; z-index: 999;
      background: rgba(0,0,0,0.8); align-items: center; justify-content: center;
      padding: 1rem;
      font-family: 'Inter', sans-serif;
    }
    .modal.active { display: flex; }
    .modal-content {
      position: relative;
      display: flex;
      flex-direction: column;
      background: #fff;
      border: 1px solid #e4e4e7;
      border-radius: 8px;
      max-width: 512px;
      width: 100%;
      max-height: min(85vh, 720px);
      box-shadow: 0 20px 25px rgba(0,0,0,0.18);
      animation: modalIn 0.2s ease;
    }
    @keyframes modalIn {
      from { opacity: 0; transform: translateY(8px) scale(0.98); }
      to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .modal-header {
      position: relative;
      display: flex;
      align-items: center;
      min-height: 60px;
      padding: 20px 24px 12px;
    }
    .modal-header h3 {
      margin: 0;
      color: #09090b;
      font-size: 18px;
      font-weight: 600;
      line-height: 1;
      letter-spacing: -0.025em;
    }
    .modal-close {
      position: absolute;
      top: 16px;
      right: 16px;
      display: grid;
      place-items: center;
      width: 28px;
      height: 28px;
      border: 0;
      border-radius: 4px;
      background: transparent;
      color: #71717a;
      font-size: 20px;
      line-height: 1;
      cursor: pointer;
      transition: background-color 0.15s ease, color 0.15s ease;
    }
    .modal-close:hover { background: #f4f4f5; color: #18181b; }
    .modal-close:focus-visible { outline: 2px solid #18181b; outline-offset: 2px; }
    .modal-body {
      padding: 8px 24px 20px;
      overflow-y: auto;
      color: #71717a;
      font-size: 14px;
      line-height: 1.5;
    }
    .modal-actions {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      padding: 16px 24px;
      border-top: 1px solid #e4e4e7;
    }
    .terms-content h4 {
      color: #18181b;
      font-size: 14px;
      font-weight: 600;
      line-height: 1.4;
      margin: 1.25rem 0 0.4rem;
    }
    .terms-content h4:first-child { margin-top: 0; }
    .terms-content p {
      color: #71717a;
      font-size: 14px;
      line-height: 1.5;
      margin: 0 0 0.4rem;
    }
    @media (max-width: 520px) {
      .modal { padding: 16px; }
      .modal-content { max-height: calc(100dvh - 32px); }
      .modal-header { padding: 20px 20px 12px; }
      .modal-body { padding: 8px 20px 20px; }
      .modal-actions { padding: 12px 20px 20px; }
      .modal-actions .btn-register { flex: 1; }
    }

    /* Responsive */
    @media (max-width: 900px) {
      body { display: block; min-height: 100vh; overflow-x: hidden; background: #ffffff; }
      .auth-wrapper { display: grid; grid-template-columns: minmax(0, 1fr); align-content: start; min-height: 100vh; overflow: hidden; background: inherit; }
      .auth-brand-panel, .brand-content { display: contents; }
      .auth-brand-panel::before { display: none; }
      .brand-title,
      .brand-trust { display: none; }
      .auth-logo { order: 2; width: clamp(88px, 24vw, 112px); margin: 0.6rem auto 0.75rem; }
      .brand-tagline { display: none; }
      .auth-form-panel { order: 3; min-width: 0; width: 100%; min-height: 0; padding: 1rem 16px; background: #ffffff; }
      .auth-form-card { width: 100%; max-width: 560px; margin: 0 auto; padding: 0; }
      .register-header { text-align: center; }
      .form-row { grid-template-columns: 1fr; gap: 1rem; }
    }

    @media (max-width: 480px) {
      .register-header h2 { font-size: 1.25rem; }
    }
  </style>
</head>
<body>

  <div class="auth-wrapper">

    <!-- Left Panel: Branding -->
    <div class="auth-brand-panel">
      <div class="brand-content">
        <h1 class="brand-title">Dasmariñas City<br />Veterinary Services</h1>
        <img src="<?php echo htmlspecialchars($appBaseUrl . '/uploads/site/DCVS%20LOGO.png', ENT_QUOTES, 'UTF-8'); ?>" alt="DCVS Logo" class="auth-logo" />
        <p class="brand-tagline">Serving the community through better animal welfare and veterinary services.</p>
      </div>

      <div class="brand-trust">
        <div class="brand-logos">
          <div class="brand-logos-track">
            <?php
            $trustLogos = ['SPCA.png', 'PAWS.png', 'CARA.png', 'AKF.png', 'WORLD ANIMAL PROTECTION.png', 'ANIMAL INDUSTRY.png', 'PAWSTURE.png', 'PARTNER.png', 'ANIMAL WELFARE AWARDS.png', 'PUBLIC SERVICE AWARDS.png', 'DCVS AWARDS.png'];
            for ($i = 0; $i < 2; $i++): foreach ($trustLogos as $logo): ?>
              <div class="brand-logo-item">
                <img src="<?php echo htmlspecialchars($appBaseUrl . '/assets/images/' . rawurlencode($logo), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(pathinfo($logo, PATHINFO_FILENAME)); ?>" />
              </div>
            <?php endforeach; endfor; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Right Panel: Register Form -->
    <div class="auth-form-panel">
      <div class="auth-form-card">

        <div class="register-header">
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
            <span id="registerAlertMsg">Review the highlighted fields and try again.</span>
          </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['register_success'])): ?>
          <div class="auth-alert auth-alert-success" id="registerSuccess">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($_SESSION['register_success']); unset($_SESSION['register_success']); ?></span>
          </div>
        <?php endif; ?>

        <form id="registerForm" class="register-form" action="register-barangay.php" method="POST" novalidate>
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
                <input type="tel" id="phone" name="phone" inputmode="numeric" pattern="09[0-9]{9}" placeholder="09XXXXXXXXX" required />
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
                <input type="password" id="regPassword" class="password-input" name="password" placeholder="8+ characters" required />
                <button type="button" class="toggle-password" id="toggleRegPassword" aria-label="Toggle password visibility">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
              <span class="field-error" id="regPasswordError"></span>
              <div class="password-strength" id="passwordStrength" style="display:none;">
                <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                <span class="strength-label" id="strengthLabel">Weak</span>
              </div>
              <ul class="password-requirements" id="passwordRequirements" aria-label="Password requirements">
                <li data-password-rule="length">At least 8 characters</li>
                <li data-password-rule="uppercase">At least one uppercase letter (A-Z)</li>
                <li data-password-rule="lowercase">At least one lowercase letter (a-z)</li>
                <li data-password-rule="number">At least one number (0-9)</li>
                <li data-password-rule="special">At least one special character (such as !, @, #, or $)</li>
              </ul>
            </div>
            <div class="form-group" id="confirmPasswordGroup">
              <label for="confirmPassword">Confirm Password <span class="required">*</span></label>
              <div class="input-wrapper">
                <i class="fas fa-lock input-icon"></i>
                <input type="password" id="confirmPassword" class="password-input" name="confirm_password" placeholder="Re-enter your password" required />
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
              I have read and agree to the <a href="#" onclick="event.preventDefault(); openTermsModal();" class="terms-link">Terms and Conditions</a>
            </label>
            <span class="field-error" id="termsError"></span>
          </div>

          <button type="submit" class="btn-register" id="registerBtn" style="margin-top:4px;">
            <span class="btn-text">Create Account</span>
            <i class="fas fa-user-plus btn-icon"></i>
            <span class="btn-loader" style="display:none;"><i class="fas fa-spinner fa-spin"></i></span>
          </button>

        </form>

        <div class="register-divider"><span>or</span></div>

        <p class="register-switch">
          Already have a barangay account?
          <a href="<?php echo htmlspecialchars($appBaseUrl . '/login.php', ENT_QUOTES, 'UTF-8'); ?>">Sign in here <i class="fas fa-arrow-right"></i></a>
        </p>

        <div class="back-home">
          <a href="landing-page.php"><i class="fas fa-arrow-left"></i> Back to Home</a>
        </div>

      </div>
    </div>

  </div>

  <!-- Terms & Conditions Modal -->
  <div class="modal" id="termsModal" aria-hidden="true">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="termsDialogTitle">
      <div class="modal-header">
        <h3 id="termsDialogTitle">Terms and Conditions</h3>
        <button type="button" class="modal-close" onclick="closeTermsModal()" aria-label="Close">&times;</button>
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
        <button class="btn-register btn-secondary" onclick="closeTermsModal()">Cancel</button>
        <button class="btn-register" id="agreeTermsBtn" style="background:#000;">I Agree</button>
      </div>
    </div>
  </div>

  <script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/philippine-phone-validation.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
  <script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/auth.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>