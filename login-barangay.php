<?php
require_once 'db.php';

session_name('DCVS_BARANGAY_SESSION');
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
if ($autoUser && ($autoUser['role'] ?? '') === 'barangay') {
    header('Location: dashboard-barangay.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['login_error'] = 'Invalid form submission. Please try again.';
        header('Location: login-barangay.php');
        exit;
    }
    session_regenerate_id(true);
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

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
        header('Location: login-barangay.php');
        exit;
    }

    $stmt = $conn->prepare("SELECT user_id, username, password, email, full_name, role, barangay_id, phone, address, status, avatar_url, reference_id, archived_at, password_updated_at FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // Brute-force protection
        if (isset($_SESSION['login_lockout_until']) && time() < $_SESSION['login_lockout_until']) {
            $remaining = ceil(($_SESSION['login_lockout_until'] - time()) / 60);
            $_SESSION['login_error'] = "Too many failed attempts. Please try again in $remaining minute(s).";
            header('Location: login-barangay.php');
            exit;
        }
        if (isset($_SESSION['login_lockout_until']) && time() >= $_SESSION['login_lockout_until']) {
            unset($_SESSION['login_lockout_until'], $_SESSION['login_failures']);
        }

        if (password_verify($password, $user['password'])) {
            if ($user['status'] !== 'active') {
                if (!empty($user['archived_at'])) {
                    $_SESSION['login_error'] = "Your account has been archived. Please contact the administrator to restore it.";
                } else {
                    $_SESSION['login_error'] = "Your account has been deactivated. Please contact the administrator.";
                }
                header('Location: login-barangay.php');
                exit;
            }

            if ($user['role'] !== 'barangay') {
                $_SESSION['login_error'] = "Unauthorized access. Barangay credentials required.";
                header('Location: login-barangay.php');
                exit;
            }

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

            $action = "Barangay Login";
            $details = "Barangay officer logged into portal.";
            $ip = $_SERVER['REMOTE_ADDR'];
            $logStmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $logStmt->bind_param("isss", $user['user_id'], $action, $details, $ip);
            $logStmt->execute();
            $logStmt->close();

            unset($_SESSION['login_failures'], $_SESSION['login_lockout_until']);

            // Remember Me
            if (!empty($_POST['remember'])) {
                setRememberMe($conn, $user['user_id']);
            }

            header('Location: dashboard-barangay.php');
            exit;
        } else {
            $_SESSION['login_failures'] = ($_SESSION['login_failures'] ?? 0) + 1;
            if ($_SESSION['login_failures'] >= 10) {
                $_SESSION['login_lockout_until'] = time() + (15 * 60);
            } elseif ($_SESSION['login_failures'] >= 5) {
                sleep(min(30, $_SESSION['login_failures'] * 2));
            }
            $_SESSION['login_error'] = "Invalid email or password.";
            header('Location: login-barangay.php');
            exit;
        }
    } else {
        $_SESSION['login_failures'] = ($_SESSION['login_failures'] ?? 0) + 1;
        if ($_SESSION['login_failures'] >= 10) {
            $_SESSION['login_lockout_until'] = time() + (15 * 60);
        } elseif ($_SESSION['login_failures'] >= 5) {
            sleep(min(30, $_SESSION['login_failures'] * 2));
        }
        $_SESSION['login_error'] = "Invalid email or password.";
        header('Location: login-barangay.php');
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
  <title>Barangay Login — Dasmariñas City Veterinary Services</title>

  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="assets/css/auth.css" />
  <link rel="stylesheet" href="assets/css/responsive.css" />

  <style>
    /* Brand block (logo + title + subtitle) is vertically aligned with the
       "Barangay Portal" heading of the centered login form via the script below.
       The login form itself keeps its original centered position. */
    .auth-brand-panel.login-brand .brand-content {
      flex: 0 0 auto;
      justify-content: flex-start;
      margin: 0;
    }
    .auth-brand-panel.login-brand .brand-trust {
      position: absolute;
      left: 0;
      right: 0;
      bottom: 0;
      margin: 0;
    }
    @media (max-width: 900px) {
      .auth-brand-panel.login-brand .brand-trust {
        position: static;
        margin: 5rem calc(-1 * (var(--pad-x) + 60px)) 0;
      }
    }
  </style>
</head>
<body class="auth-body">

  <div class="bg-blob blob-1"></div>
  <div class="bg-blob blob-2"></div>
  <div class="bg-blob blob-3"></div>

  <div class="auth-wrapper">

    <div class="auth-brand-panel login-brand">
      <div class="brand-content">
        <div class="brand-heading">
          <img src="uploads/site/logo.png" alt="DCVS Logo" class="auth-logo" />
          <h1 class="brand-title">Dasmariñas City<br />Veterinary Services</h1>
        </div>
        <p class="brand-subtitle">Barangay Portal — Report Validation &amp; Case Management</p>
      </div>

      <div class="brand-trust">
        <p class="brand-trust-label" style="font-style:italic;">Better Services for a Better Community</p>
        <div class="brand-logos">
          <div class="brand-logos-track">
            <?php
            $trustLogos = ['SPCA.png', 'PAWS.png', 'CARA.png', 'AKF.png', 'WORLD ANIMAL PROTECTION.png', 'ANIMAL INDUSTRY.png', 'PAWSTURE.png', 'PARTNER.png', 'ANIMAL WELFARE AWARDS.png', 'PUBLIC SERVICE AWARDS.png', 'DCVS AWARDS.png'];
            for ($i = 0; $i < 2; $i++): foreach ($trustLogos as $logo): ?>
              <div class="brand-logo-item">
                <img src="<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars(pathinfo($logo, PATHINFO_FILENAME)); ?>" />
              </div>
            <?php endforeach; endfor; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="auth-form-panel">
      <div class="auth-form-card">

        <div class="form-header">
          <h2>Barangay Portal</h2>
          <p>Sign in with your barangay officer credentials</p>
        </div>

        <?php if (isset($_SESSION['login_error'])): ?>
          <div class="auth-alert auth-alert-error" id="loginAlert">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($_SESSION['login_error']); unset($_SESSION['login_error']); ?></span>
          </div>
        <?php else: ?>
          <div class="auth-alert auth-alert-error" id="loginAlert" style="display:none;">
            <i class="fas fa-exclamation-circle"></i>
            <span id="loginAlertMsg">Invalid email or password.</span>
          </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['login_notice'])): ?>
          <div class="auth-alert auth-alert-info">
            <i class="fas fa-info-circle"></i>
            <span><?php echo htmlspecialchars($_SESSION['login_notice']); unset($_SESSION['login_notice']); ?></span>
          </div>
        <?php endif; ?>

        <form id="loginForm" class="auth-form" action="login-barangay.php" method="POST" novalidate>
          <?php echo csrfField(); ?>

          <div class="form-group" id="emailGroup">
            <label for="email">Email Address</label>
            <div class="input-wrapper">
              <i class="fas fa-envelope input-icon"></i>
              <input type="email" id="email" name="email" placeholder="e.g. captain@barangay.gov.ph" autocomplete="email" required />
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
              <span class="checkmark"></span>
              Remember me
            </label>
            <a href="forgot-password.php" class="forgot-link">Forgot password?</a>
          </div>

          <button type="submit" class="btn-auth" id="loginBtn">
            <span class="btn-text">Sign In</span>
            <i class="fas fa-arrow-right btn-icon"></i>
            <span class="btn-loader" style="display:none;"><i class="fas fa-spinner fa-spin"></i></span>
          </button>

        </form>

        <div class="auth-divider">
          <span>or</span>
        </div>

        <p class="auth-switch">
          Don't have a barangay account?
          <a href="register-barangay.php" class="switch-link">Register here <i class="fas fa-arrow-right"></i></a>
        </p>

        <div class="back-to-home">
          <a href="landing-page.php"><i class="fas fa-arrow-left"></i> Back to Home</a>
        </div>

      </div>
    </div>

  </div>

  <script src="assets/js/auth.js"></script>

  <script>
    (function () {
      var panel = document.querySelector('.auth-brand-panel.login-brand');
      var content = panel ? panel.querySelector('.brand-content') : null;
      var card = document.querySelector('.auth-form-panel .auth-form-card');
      if (!content || !card) return;

      function alignBrand() {
        if (window.innerWidth <= 900) {
          content.style.position = '';
          content.style.top = '';
          content.style.transform = '';
          content.style.left = '';
          return;
        }
        // Level the brand heading (logo + title + subtitle) with the
        // form heading by placing its top edge at the same height as the card's top.
        var top = card.getBoundingClientRect().top;
        if (top >= 0) {
          content.style.position = 'absolute';
          content.style.top = top + 'px';
          content.style.transform = 'translateX(-50%)';
          content.style.left = '50%';
        }
      }

      alignBrand();
      window.addEventListener('resize', alignBrand);
      window.addEventListener('load', alignBrand);
    })();
  </script>
</body>
</html>
