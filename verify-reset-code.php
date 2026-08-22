<?php
require_once 'db.php';

$token = $_GET['token'] ?? '';
$error = null;
$success = null;
$resetEmail = '';

// Look up the token
if (!empty($token)) {
    $stmt = $conn->prepare("SELECT email FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $resetEmail = $row['email'] ?? '';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['token'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($token)) {
        $error = 'Invalid reset session. Please request a new verification code.';
    } elseif (empty($code) || !preg_match('/^\d{6}$/', $code)) {
        $error = 'Please enter a valid 6-digit verification code.';
    } elseif (empty($password)) {
        $error = 'Please enter a new password.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        // Verify the code for this token
        $stmt = $conn->prepare("SELECT id, email FROM password_resets WHERE token = ? AND code = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
        $stmt->bind_param("ss", $token, $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ?, password_updated_at = NOW() WHERE email = ?");
            $stmt->bind_param("ss", $hashedPassword, $row['email']);
            $stmt->execute();
            $stmt->close();

            // Mark the code as used
            $stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
            $stmt->bind_param("i", $row['id']);
            $stmt->execute();
            $stmt->close();

            // Invalidate any other unused codes for this email
            $stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND used = 0");
            $stmt->bind_param("s", $row['email']);
            $stmt->execute();
            $stmt->close();

            $success = 'Your password has been reset successfully. You can now log in with your new password.';
        } else {
            $error = 'Invalid or expired verification code. Please request a new one.';
        }
    }
}

// After successful POST, if we still have token and email but no success, re-fetch email for display
if (empty($resetEmail) && !empty($token) && empty($success)) {
    $stmt = $conn->prepare("SELECT email FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $resetEmail = $row['email'] ?? '';
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verify Code — DCVS Dasmariñas</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="icon" type="image/x-icon" href="favicon.ico" />
  <style>
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    :root {
      --green: #0E8131;
      --green-dark: #065729;
      --green-light: #f0fdf4;
      --bg: #f4f5f7;
      --surface: #ffffff;
      --text: #0f172a;
      --text-muted: #64748b;
      --text-light: #94a3b8;
      --border: #e2e8f0;
      --radius: 16px;
      --shadow: 0 4px 24px rgba(0,0,0,0.06), 0 1px 4px rgba(0,0,0,0.04);
    }

    body {
      font-family: 'Inter', 'Segoe UI', sans-serif;
      background: var(--bg);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
    }

    .reset-card {
      background: var(--surface);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      width: 100%;
      max-width: 420px;
      padding: 2.25rem 2rem 2rem;
    }

    .reset-header {
      text-align: center;
      margin-bottom: 1.75rem;
    }

    .reset-header .icon-wrap {
      width: 48px;
      height: 48px;
      background: var(--green-light);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 0.75rem;
    }

    .reset-header .icon-wrap i {
      font-size: 1.25rem;
      color: var(--green);
    }

    .reset-header h2 {
      font-family: 'Montserrat', sans-serif;
      font-size: 1.35rem;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 0.25rem;
    }

    .reset-header p {
      font-size: 0.875rem;
      color: var(--text-muted);
      line-height: 1.5;
    }

    .reset-header .email-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      background: var(--green-light);
      color: var(--green);
      font-size: 0.8rem;
      font-weight: 600;
      padding: 0.25rem 0.75rem;
      border-radius: 20px;
      margin-top: 0.5rem;
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .form-group {
      margin-bottom: 1.25rem;
    }

    .form-group label {
      display: block;
      font-size: 0.825rem;
      font-weight: 600;
      color: var(--text);
      margin-bottom: 0.4rem;
    }

    .form-group input {
      width: 100%;
      padding: 0.75rem 1rem;
      border: 1.5px solid var(--border);
      border-radius: 10px;
      font-size: 0.925rem;
      font-family: inherit;
      color: var(--text);
      background: #fafbfc;
      transition: border-color 0.2s, box-shadow 0.2s;
      outline: none;
    }

    .form-group input:focus {
      border-color: var(--green);
      box-shadow: 0 0 0 3px rgba(14,129,49,0.1);
      background: #fff;
    }

    .form-group.has-error input {
      border-color: #ef4444;
    }

    .field-error {
      display: block;
      font-size: 0.75rem;
      color: #ef4444;
      margin-top: 0.3rem;
      min-height: 1rem;
    }

    .code-input {
      text-align: center;
      font-size: 1.5rem !important;
      font-weight: 700 !important;
      letter-spacing: 10px !important;
      font-family: 'Montserrat', monospace !important;
      padding: 0.85rem 0.5rem !important;
    }

    .input-wrapper {
      position: relative;
    }

    .input-wrapper input {
      padding-right: 2.75rem;
    }

    .toggle-password {
      position: absolute;
      right: 2px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: var(--text-light);
      font-size: 1rem;
      cursor: pointer;
      padding: 0.5rem;
      line-height: 1;
    }

    .toggle-password:hover { color: var(--text-muted); }

    .btn-reset {
      width: 100%;
      padding: 0.8rem 1.5rem;
      background: var(--text);
      color: #fff;
      border: none;
      border-radius: 10px;
      font-family: 'Montserrat', sans-serif;
      font-size: 0.925rem;
      font-weight: 600;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      transition: background 0.2s, opacity 0.2s;
      margin-top: 0.5rem;
    }

    .btn-reset:hover { background: #1e293b; }
    .btn-reset:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }

    .btn-reset .btn-loader { display: none; }

    .alert {
      display: flex;
      align-items: flex-start;
      gap: 0.6rem;
      padding: 0.75rem 1rem;
      border-radius: 10px;
      font-size: 0.85rem;
      line-height: 1.45;
      margin-bottom: 1.25rem;
    }

    .alert-error {
      background: #fef2f2;
      color: #991b1b;
      border: 1px solid #fecaca;
    }

    .alert-error i { color: #ef4444; margin-top: 0.1rem; }

    .alert-success {
      background: #f0fdf4;
      color: #065f46;
      border: 1px solid #bbf7d0;
    }

    .alert-success i { color: var(--green); margin-top: 0.1rem; }

    .alert i { flex-shrink: 0; font-size: 0.95rem; }

    .divider {
      text-align: center;
      margin: 1.25rem 0;
      font-size: 0.8rem;
      color: var(--text-light);
      position: relative;
    }

    .divider::before,
    .divider::after {
      content: '';
      position: absolute;
      top: 50%;
      width: 42%;
      height: 1px;
      background: var(--border);
    }

    .divider::before { left: 0; }
    .divider::after { right: 0; }

    .footer-links {
      text-align: center;
      font-size: 0.875rem;
    }

    .footer-links a {
      color: var(--green);
      font-weight: 600;
      text-decoration: none;
      transition: color 0.2s;
    }

    .footer-links a:hover { color: var(--green-dark); text-decoration: underline; }

    .footer-links .back-link {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      margin-top: 0.75rem;
      color: var(--text-muted);
      font-weight: 500;
      font-size: 0.85rem;
    }

    .footer-links .back-link:hover { color: var(--text); }

    /* Responsive */
    @media (max-width: 480px) {
      body { padding: 1rem; }
      .reset-card { padding: 1.75rem 1.25rem 1.5rem; }
      .reset-header h2 { font-size: 1.2rem; }
    }
  </style>
</head>
<body>

  <div class="reset-card">

    <?php if ($success): ?>
    <div class="alert alert-success">
      <i class="fas fa-check-circle"></i>
      <span><?php echo htmlspecialchars($success); ?></span>
    </div>
    <div style="text-align:center;margin-top:1.25rem;">
      <a href="login.php" class="btn-reset" style="display:inline-flex;width:auto;padding:0.8rem 2rem;text-decoration:none;">
        <span>Go to Login</span>
        <i class="fas fa-arrow-right"></i>
      </a>
    </div>
    <?php endif; ?>

    <?php if (!$success): ?>

    <?php if ($error): ?>
    <div class="alert alert-error">
      <i class="fas fa-exclamation-circle"></i>
      <span><?php echo htmlspecialchars($error); ?></span>
    </div>
    <?php endif; ?>

    <div class="reset-header">
      <div class="icon-wrap">
        <i class="fas fa-hashtag"></i>
      </div>
      <h2>Verify Code</h2>
      <p>Enter the 6-digit code sent to your email</p>
      <?php if ($resetEmail): ?>
      <span class="email-badge">
        <i class="fas fa-envelope"></i>
        <?php echo htmlspecialchars($resetEmail); ?>
      </span>
      <?php endif; ?>
    </div>

    <?php if ($resetEmail): ?>
    <form id="verifyForm" action="verify-reset-code.php" method="POST" novalidate>
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>" />

      <div class="form-group" id="codeGroup">
        <label for="code">Verification Code</label>
        <input type="text" id="code" name="code" class="code-input" placeholder="000000" maxlength="6" inputmode="numeric" pattern="\d{6}" autocomplete="one-time-code" required />
        <span class="field-error" id="codeError"></span>
      </div>

      <div class="form-group" id="passwordGroup">
        <label for="password">New Password</label>
        <div class="input-wrapper">
          <input type="password" id="password" name="password" placeholder="At least 8 characters" autocomplete="new-password" required minlength="8" />
          <button type="button" class="toggle-password" id="togglePassword" aria-label="Toggle password visibility">
            <i class="fas fa-eye"></i>
          </button>
        </div>
        <span class="field-error" id="passwordError"></span>
      </div>

      <div class="form-group" id="confirmGroup">
        <label for="confirm_password">Confirm New Password</label>
        <div class="input-wrapper">
          <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your new password" autocomplete="new-password" required />
          <button type="button" class="toggle-password" id="toggleConfirm" aria-label="Toggle password visibility">
            <i class="fas fa-eye"></i>
          </button>
        </div>
        <span class="field-error" id="confirmError"></span>
      </div>

      <button type="submit" class="btn-reset" id="resetBtn">
        <span class="btn-text">Reset Password</span>
        <i class="fas fa-check-circle"></i>
        <span class="btn-loader"><i class="fas fa-spinner fa-spin"></i></span>
      </button>
    </form>

    <div class="divider">or</div>

    <div class="footer-links">
      <a href="forgot-password.php"><i class="fas fa-redo"></i> Request a new code</a>
      <br />
      <a href="login.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>

    <?php else: /* No valid token */ ?>
    <div class="alert alert-error">
      <i class="fas fa-exclamation-circle"></i>
      <span>This reset link is invalid or has expired. Please request a new verification code.</span>
    </div>
    <div class="footer-links" style="margin-top:1.25rem;">
      <a href="forgot-password.php"><i class="fas fa-redo"></i> Request a new code</a>
      <br />
      <a href="login.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>

  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const form = document.getElementById('verifyForm');
      if (!form) return;

      const codeInput = document.getElementById('code');
      const passwordInput = document.getElementById('password');
      const confirmInput = document.getElementById('confirm_password');
      const resetBtn = document.getElementById('resetBtn');

      function setupToggle(toggleId, inputId) {
        const toggle = document.getElementById(toggleId);
        const input = document.getElementById(inputId);
        if (!toggle || !input) return;
        toggle.addEventListener('click', () => {
          const isHidden = input.type === 'password';
          input.type = isHidden ? 'text' : 'password';
          toggle.querySelector('i').className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
        });
      }
      setupToggle('togglePassword', 'password');
      setupToggle('toggleConfirm', 'confirm_password');

      codeInput.addEventListener('input', () => {
        codeInput.value = codeInput.value.replace(/\D/g, '').slice(0, 6);
        const error = document.getElementById('codeError');
        const group = document.getElementById('codeGroup');
        if (error) error.textContent = '';
        if (group) group.classList.remove('has-error');
      });

      passwordInput.addEventListener('input', () => {
        const error = document.getElementById('passwordError');
        const group = document.getElementById('passwordGroup');
        if (error) error.textContent = '';
        if (group) group.classList.remove('has-error');
      });

      confirmInput.addEventListener('input', () => {
        const error = document.getElementById('confirmError');
        const group = document.getElementById('confirmGroup');
        if (error) error.textContent = '';
        if (group) group.classList.remove('has-error');
      });

      function setLoading(loading) {
        resetBtn.disabled = loading;
        const text = resetBtn.querySelector('.btn-text');
        const icon = resetBtn.querySelector('.btn-icon');
        const loader = resetBtn.querySelector('.btn-loader');
        if (text) text.style.opacity = loading ? '0.6' : '1';
        if (icon) icon.style.display = loading ? 'none' : 'inline-block';
        if (loader) loader.style.display = loading ? 'inline-block' : 'none';
      }

      form.addEventListener('submit', (e) => {
        e.preventDefault();
        let valid = true;

        [['codeGroup','codeError'], ['passwordGroup','passwordError'], ['confirmGroup','confirmError']].forEach(([g, e]) => {
          const el = document.getElementById(e);
          const grp = document.getElementById(g);
          if (el) el.textContent = '';
          if (grp) grp.classList.remove('has-error');
        });

        const code = codeInput.value.trim();
        const password = passwordInput.value;
        const confirmPassword = confirmInput.value;

        if (!code || code.length !== 6) {
          const el = document.getElementById('codeError');
          const grp = document.getElementById('codeGroup');
          if (el) el.textContent = 'Enter a valid 6-digit code.';
          if (grp) grp.classList.add('has-error');
          valid = false;
        }

        if (!password) {
          const el = document.getElementById('passwordError');
          const grp = document.getElementById('passwordGroup');
          if (el) el.textContent = 'New password is required.';
          if (grp) grp.classList.add('has-error');
          valid = false;
        } else if (password.length < 8) {
          const el = document.getElementById('passwordError');
          const grp = document.getElementById('passwordGroup');
          if (el) el.textContent = 'Password must be at least 8 characters.';
          if (grp) grp.classList.add('has-error');
          valid = false;
        }

        if (!confirmPassword) {
          const el = document.getElementById('confirmError');
          const grp = document.getElementById('confirmGroup');
          if (el) el.textContent = 'Please confirm your new password.';
          if (grp) grp.classList.add('has-error');
          valid = false;
        } else if (password !== confirmPassword) {
          const el = document.getElementById('confirmError');
          const grp = document.getElementById('confirmGroup');
          if (el) el.textContent = 'Passwords do not match.';
          if (grp) grp.classList.add('has-error');
          valid = false;
        }

        if (!valid) return;
        setLoading(true);
        form.submit();
      });
    });
  </script>
</body>
</html>