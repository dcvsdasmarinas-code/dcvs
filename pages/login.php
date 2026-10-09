<?php
require_once __DIR__ . '/../db.php';

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

// Auto-login via Remember Me cookie
$autoUser = autoLoginRememberMe($conn);
if ($autoUser) {
    header('Location: dashboard-resident.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['login_error'] = 'Invalid form submission. Please try again.';
        header('Location: login.php');
        exit;
    }

    // Check lockout BEFORE anything else
    if (isset($_SESSION['login_lockout_until']) && time() < $_SESSION['login_lockout_until']) {
        $remaining = ceil(($_SESSION['login_lockout_until'] - time()) / 60);
        $_SESSION['login_error'] = "Too many failed login attempts. Your account has been temporarily locked. Please try again in $remaining minute(s).";
        header('Location: login.php');
        exit;
    }
    if (isset($_SESSION['login_lockout_until']) && time() >= $_SESSION['login_lockout_until']) {
        unset($_SESSION['login_lockout_until'], $_SESSION['login_failures']);
    }

    session_regenerate_id(true);
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Server-side validation
    $errors = [];

    if (empty($email)) {
        $errors[] = "Email address is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    if (empty($password)) {
        $errors[] = "Password is required.";
    }

    if (!empty($errors)) {
        $_SESSION['login_error'] = implode('<br>', $errors);
        header('Location: login.php');
        exit;
    }

    // Check user in database
    $stmt = $conn->prepare("SELECT user_id, username, password, email, full_name, role, barangay_id, phone, address, status, avatar_url, reference_id, deleted_at, password_updated_at FROM users WHERE email = ?");
    if (!$stmt) {
        $_SESSION['login_error'] = "An error occurred. Please try again.";
        header('Location: login.php');
        exit;
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // Verify password
        if (password_verify($password, $user['password'])) {

            if (!empty($user['deleted_at'])) {
                $_SESSION['login_error'] = 'Your account has been deleted by the administrator. Contact us for clarification.';
                header('Location: login.php');
                exit;
            }

            // Check if account is active
            if ($user['status'] !== 'active') {
                $_SESSION['login_error'] = "Your account has been deactivated. Please contact the administrator.";
                header('Location: login.php');
                exit;
            }

            // Set session variables
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['barangay_id'] = $user['barangay_id'];
            $_SESSION['phone'] = $user['phone'];
            $_SESSION['address'] = $user['address'];
            $_SESSION['avatar_url'] = $user['avatar_url'] ?? '';
            $_SESSION['reference_id'] = $user['reference_id'] ?? '';
            $_SESSION['logged_in'] = true;

            // Log activity
            $roleLabel = ucfirst($user['role']);
            $actAction = $roleLabel . " Login";
            $actDetails = $roleLabel . " logged into the system.";
            $actIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $actLog = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $actLog->bind_param("isss", $user['user_id'], $actAction, $actDetails, $actIp);
            $actLog->execute();
            $actLog->close();

            unset($_SESSION['login_failures'], $_SESSION['login_lockout_until']);

            // Remember Me
            if (!empty($_POST['remember'])) {
                setRememberMe($conn, $user['user_id']);
            }

            // Redirect based on role
            switch ($user['role']) {
                case 'admin':
                    header('Location: role-management.php');
                    break;
                case 'staff':
                    header('Location: dashboard-staff.php');
                    break;
                case 'barangay':
                    header('Location: dashboard-barangay.php');
                    break;
                case 'resident':
                default:
                    header('Location: dashboard-resident.php');
                    break;
            }
            exit;
        } else {
            $_SESSION['login_failures'] = ($_SESSION['login_failures'] ?? 0) + 1;
            if ($_SESSION['login_failures'] >= 5) {
                $_SESSION['login_lockout_until'] = time() + (10 * 60);
                $_SESSION['login_error'] = "Too many failed login attempts. Your account has been temporarily locked. Please try again in 10 minutes.";
            } else {
                $_SESSION['login_error'] = "Invalid email or password.";
            }
            $_SESSION['login_last_email'] = $email;
            header('Location: login.php');
            exit;
        }
    } else {
        $_SESSION['login_failures'] = ($_SESSION['login_failures'] ?? 0) + 1;
        if ($_SESSION['login_failures'] >= 5) {
            $_SESSION['login_lockout_until'] = time() + (10 * 60);
            $_SESSION['login_error'] = "Too many failed login attempts. Your account has been temporarily locked. Please try again in 10 minutes.";
        } else {
            $_SESSION['login_error'] = "Invalid email or password.";
        }
        $_SESSION['login_last_email'] = $email;
        header('Location: login.php');
        exit;
    }

    $stmt->close();
}

$conn->close();

// Re-show lockout alert on page refresh if still locked out
if (isset($_SESSION['login_lockout_until']) && time() < $_SESSION['login_lockout_until']) {
    $remaining = ceil(($_SESSION['login_lockout_until'] - time()) / 60);
    $_SESSION['login_error'] = "Too many failed login attempts. Your account has been temporarily locked. Please try again in $remaining minute(s).";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Login to the Dasmariñas City Veterinary Services animal welfare reporting and record management system." />
  <title>Login — Dasmariñas City Veterinary Services</title>

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
      --bg: #f8fafc;
    }

    body {
      font-family: 'Open Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: var(--bg);
      min-height: 100vh;
      -webkit-font-smoothing: antialiased;
      line-height: 1.6;
    }

    /* ── Split Layout ── */
    .auth-wrapper {
      display: grid;
      grid-template-columns: 1fr 1fr;
      min-height: 100vh;
      width: 100%;
    }

    /* ── Left Brand Panel ── */
    .auth-brand-panel {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 4rem 4rem 2.5rem;
      background: linear-gradient(135deg, var(--green-primary) 0%, var(--green-dark) 100%);
      position: relative;
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

    /* ── Right Form Panel ── */
    .auth-form-panel {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 3rem;
      overflow-y: auto;
      max-height: 100vh;
      background: var(--bg);
    }

    .auth-form-card {
      width: 100%;
      max-width: 440px;
      background: var(--surface);
      border: 1px solid var(--border-color);
      border-radius: 3px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 16px rgba(0,0,0,0.04);
      padding: 2.5rem 2.5rem 2rem;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(12px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* ── Login Logo ── */
    .login-logo {
      display: flex;
      justify-content: center;
      padding-top: 0.25rem;
      margin-bottom: 1.75rem;
    }

    .login-logo img {
      width: 80px;
      height: 80px;
      object-fit: contain;
    }

    /* ── Header ── */
    .login-header {
      text-align: center;
      margin-bottom: 1.75rem;
    }

    .login-header h2 {
      font-family: 'Inter', sans-serif;
      font-size: 1.4rem;
      font-weight: 700;
      color: var(--text-main);
      margin-bottom: 0.35rem;
      letter-spacing: -0.02em;
    }

    .login-header p {
      font-size: 0.875rem;
      color: var(--text-muted);
    }

    /* ── Alert ── */
    .auth-alert {
      display: flex;
      align-items: center;
      gap: 0.65rem;
      padding: 0.75rem 1rem;
      border-radius: 6px;
      font-size: 0.85rem;
      font-weight: 500;
      margin-bottom: 1.25rem;
    }
    .auth-alert-error {
      background: var(--error-bg);
      color: var(--error);
      border: 1px solid rgba(239,68,68,0.15);
    }
    .auth-alert-info {
      background: #eff6ff;
      color: #1d4ed8;
      border: 1px solid rgba(29,78,216,0.15);
    }

    /* ── Form ── */
    .login-form {
      display: flex;
      flex-direction: column;
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

    .input-wrapper input {
      width: 100%;
      padding: 0.7rem 0.875rem 0.7rem 2.6rem;
      border: 1.5px solid var(--border-color);
      border-radius: 6px;
      font-family: 'Open Sans', sans-serif;
      font-size: 0.9rem;
      color: var(--text-main);
      background: var(--surface);
      transition: border-color 0.25s, box-shadow 0.25s;
      outline: none;
    }

    .input-wrapper input::placeholder { color: var(--text-light); }
    .input-wrapper input:hover { border-color: #cbd5e1; }
    .input-wrapper input:focus {
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

    .form-group.has-error input {
      border-color: var(--error);
      box-shadow: 0 0 0 3px var(--error-bg);
    }
    .form-group.has-error .input-icon { color: var(--error); }

    /* ── Form Options ── */
    .form-options {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: 0.25rem;
      margin-bottom: 0.5rem;
    }

    .remember-me {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.85rem;
      color: var(--text-muted);
      cursor: pointer;
      user-select: none;
    }

    .remember-me input[type="checkbox"] {
      width: 16px; height: 16px;
      accent-color: var(--green-primary);
      cursor: pointer; border-radius: 3px;
    }

    .forgot-link {
      font-size: 0.85rem;
      color: var(--green-primary);
      font-weight: 600;
      transition: color 0.2s;
    }
    .forgot-link:hover { color: var(--green-dark); text-decoration: underline; }

    /* ── Button (shadcn) ── */
    .btn-signin {
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
      margin-top: 0.25rem;
    }
    .btn-signin:hover { background: #27272a; }
    .btn-signin:active { background: #18181b; box-shadow: none; }
    .btn-signin:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }

    /* ── Divider ── */
    .login-divider {
      display: flex;
      align-items: center;
      gap: 0.85rem;
      margin: 1.5rem 0;
      color: var(--text-light);
      font-size: 0.8rem;
      font-weight: 500;
    }
    .login-divider::before,
    .login-divider::after {
      content: '';
      flex: 1;
      height: 1px;
      background: var(--border-color);
    }

    /* ── Switch ── */
    .login-switch {
      text-align: center;
      font-size: 0.875rem;
      color: var(--text-muted);
    }
    .login-switch a {
      color: var(--green-primary);
      font-weight: 600;
      transition: color 0.2s;
      display: inline-flex;
      align-items: center;
      gap: 0.3rem;
      margin-left: 0.2rem;
      text-decoration: none;
    }
    .login-switch a:hover { color: var(--green-dark); text-decoration: underline; }
    .login-switch a i { font-size: 0.7rem; transition: transform 0.2s; text-decoration: none; }
    .login-switch a:hover i { transform: translateX(3px); }

    /* ── Back to Home ── */
    .back-home {
      text-align: center;
      margin-top: 1.5rem;
    }
    .back-home a {
      font-size: 0.8rem;
      color: var(--text-light);
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      transition: color 0.2s;
      font-weight: 500;
      text-decoration: none;
    }
    .back-home a:hover { color: var(--text-main); text-decoration: underline; }
    .back-home a i { transition: transform 0.2s; text-decoration: none; }
    .back-home a:hover i { transform: translateX(-3px); }

    /* ── Responsive ── */
    @media (max-width: 900px) {
      .auth-wrapper { grid-template-columns: 1fr; }
      .auth-brand-panel { min-height: auto; padding: 3rem 2rem 1.5rem; }
      .brand-trust { position: static; margin: 3rem calc(-1 * (4rem + 60px)) 0; }
      .auth-form-panel { padding: 2rem 1rem; height: auto; max-height: none; }
    }

    @media (max-width: 480px) {
      .auth-brand-panel { padding: 2rem 1.5rem 1rem; }
      .brand-trust { margin: 2rem calc(-1 * (1.5rem + 60px)) 0; }
      .auth-form-panel { padding: 1.5rem 1rem; }
      .login-header h2 { font-size: 1.25rem; }
    }
  </style>
</head>
<body>

  <div class="auth-wrapper">

    <!-- Left Panel: Branding -->
    <div class="auth-brand-panel">
      <div class="brand-content">
        <h1 class="brand-title">Dasmariñas City<br />Veterinary Services</h1>
        <img src="uploads/site/DCVS LOGO.png" alt="DCVS Logo" class="auth-logo" />
        <p class="brand-tagline">Serving the community through better animal welfare and veterinary services.</p>
      </div>

      <div class="brand-trust">
        <div class="brand-logos">
          <div class="brand-logos-track">
            <?php
            $trustLogos = ['SPCA.png', 'PAWS.png', 'CARA.png', 'AKF.png', 'WORLD ANIMAL PROTECTION.png', 'ANIMAL INDUSTRY.png', 'PAWSTURE.png', 'PARTNER.png', 'ANIMAL WELFARE AWARDS.png', 'PUBLIC SERVICE AWARDS.png', 'DCVS AWARDS.png'];
            for ($i = 0; $i < 2; $i++): foreach ($trustLogos as $logo): ?>
              <div class="brand-logo-item">
                <img src="assets/images/<?php echo rawurlencode($logo); ?>" alt="<?php echo htmlspecialchars(pathinfo($logo, PATHINFO_FILENAME)); ?>" />
              </div>
            <?php endforeach; endfor; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Right Panel: Login Form -->
    <div class="auth-form-panel">
      <div class="auth-form-card">

        <div class="login-logo">
          <img src="uploads/site/DCVS LOGO.png" alt="DCVS Logo" />
        </div>

        <div class="login-header">
          <h2>Welcome Back</h2>
          <p>Sign in to your DCVS account to continue</p>
        </div>

        <?php if (isset($_SESSION['login_error'])): ?>
          <div class="auth-alert auth-alert-error" id="loginAlert">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($_SESSION['login_error']); unset($_SESSION['login_error']); ?></span>
          </div>
        <?php else: ?>
          <div class="auth-alert auth-alert-error" id="loginAlert" style="display:none;">
            <i class="fas fa-exclamation-circle"></i>
            <span id="loginAlertMsg">Unable to sign in. Please check your credentials and try again.</span>
          </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['login_notice'])): ?>
          <div class="auth-alert auth-alert-info">
            <i class="fas fa-info-circle"></i>
            <span><?php echo htmlspecialchars($_SESSION['login_notice']); unset($_SESSION['login_notice']); ?></span>
          </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['register_success'])): ?>
          <?php
            $portalToasts = [$_SESSION['register_success']];
            unset($_SESSION['register_success']);
            include __DIR__ . '/../includes/portal-toast.php';
          ?>
        <?php endif; ?>

        <form id="loginForm" class="login-form" action="login.php" method="POST" novalidate>
          <?php echo csrfField(); ?>

          <div class="form-group" id="emailGroup">
            <label for="email">Email Address</label>
            <div class="input-wrapper">
              <i class="fas fa-envelope input-icon"></i>
              <input type="email" id="email" name="email" placeholder="e.g. juan@email.com" autocomplete="email" required value="<?php echo htmlspecialchars($_SESSION['login_last_email'] ?? ''); unset($_SESSION['login_last_email']); ?>" />
            </div>
            <span class="field-error" id="emailError"></span>
          </div>

          <div class="form-group" id="passwordGroup">
            <label for="password">Password</label>
            <div class="input-wrapper">
              <i class="fas fa-lock input-icon"></i>
              <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required />
              <button type="button" class="toggle-password" id="togglePassword" aria-label="Toggle password visibility">
                <i class="fas fa-eye"></i>
              </button>
            </div>
            <span class="field-error" id="passwordError"></span>
          </div>

          <div class="form-options">
            <label class="remember-me">
              <input type="checkbox" name="remember" id="rememberMe" />
              Remember me
            </label>
            <a href="forgot-password.php" class="forgot-link">Forgot password?</a>
          </div>

          <button type="submit" class="btn-signin" id="loginBtn">
            <span class="btn-text">Sign In</span>
            <i class="fas fa-arrow-right btn-icon"></i>
            <span class="btn-loader" style="display:none;"><i class="fas fa-spinner fa-spin"></i></span>
          </button>
        </form>

        <div class="login-divider"><span>or</span></div>

        <p class="login-switch">
          Don't have an account?
          <a href="register.php">Create one now <i class="fas fa-arrow-right"></i></a>
        </p>

        <div class="back-home">
          <a href="landing-page.php"><i class="fas fa-arrow-left"></i> Back to Home</a>
        </div>

      </div>
    </div>

  </div>

  <script src="assets/js/auth.js"></script>
</body>
</html>