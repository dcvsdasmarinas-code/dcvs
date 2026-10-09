<?php
require_once __DIR__ . '/../db.php';

$token = trim($_GET['token'] ?? '');
$error = null;
$success = null;
$resetEmail = '';

// Look up the token
if (!empty($token)) {
    $stmt = $conn->prepare("SELECT email, verified FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $resetEmail = $row['email'] ?? '';
    if ($row && !$row['verified']) {
        $error = 'Your request has not been verified yet. Please confirm via the email link first.';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['token'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($token)) {
        $error = 'Invalid reset session. Please request a new verification link.';
    } elseif (empty($password)) {
        $error = 'Please enter a new password.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        // Verify the token
        $stmt = $conn->prepare("SELECT id, email, verified FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            if (!$row['verified']) {
                $error = 'Your request has not been verified yet. Please confirm via the email link first.';
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ?, password_updated_at = NOW() WHERE email = ?");
                $stmt->bind_param("ss", $hashedPassword, $row['email']);
                $stmt->execute();
                $stmt->close();

                // Mark the token as used
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
            }
        } else {
            $error = 'This verification link is invalid or has expired. Please request a new one.';
        }
    }
}

$conn->close();
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Reset Password — DCVS Dasmariñas</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="icon" type="image/x-icon" href="favicon.ico" />
  <style>
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    :root {
      --text-main: #0f172a;
      --text-muted: #64748b;
      --border-color: #e2e8f0;
      --error: #ef4444;
      --error-bg: #fef2f2;
      --surface: #ffffff;
      --bg: #f8fafc;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--bg);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
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

    .form-header {
      text-align: center;
      margin-bottom: 1.5rem;
    }

    .form-header .icon-wrap {
      width: 48px;
      height: 48px;
      background: #f0fdf4;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 0.75rem;
    }

    .form-header .icon-wrap i {
      font-size: 1.15rem;
      color: #0a7a3b;
    }

    .form-header .icon-wrap.error-icon {
      background: var(--error-bg);
    }

    .form-header .icon-wrap.error-icon i {
      color: var(--error);
    }

    .form-header h2 {
      font-family: 'Inter', sans-serif;
      font-size: 1.4rem;
      font-weight: 700;
      color: var(--text-main);
      margin-bottom: 0.35rem;
      letter-spacing: -0.02em;
    }

    .form-header p {
      font-size: 0.875rem;
      color: var(--text-muted);
      line-height: 1.5;
    }

    .email-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      background: #f0fdf4;
      color: #0a7a3b;
      font-size: 0.8rem;
      font-weight: 600;
      padding: 0.3rem 0.75rem;
      border-radius: 6px;
      margin-top: 0.5rem;
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .form-group {
      margin-bottom: 1rem;
    }

    .form-group label {
      display: block;
      font-size: 0.875rem;
      font-weight: 600;
      color: var(--text-main);
      margin-bottom: 0.4rem;
    }

    .form-group input {
      width: 100%;
      height: 36px;
      padding: 0 0.875rem;
      border: 1.5px solid var(--border-color);
      border-radius: 6px;
      font-family: 'Inter', sans-serif;
      font-size: 0.9rem;
      color: var(--text-main);
      background: var(--surface);
      outline: none;
      transition: border-color 0.25s, box-shadow 0.25s;
    }

    .form-group input::placeholder { color: #94a3b8; }
    .form-group input:hover { border-color: #cbd5e1; }

    .form-group input:focus {
      border-color: #0a7a3b;
      box-shadow: 0 0 0 3px rgba(10,122,59,0.08);
    }

    .form-group.has-error input {
      border-color: var(--error);
      box-shadow: 0 0 0 3px var(--error-bg);
    }

    .field-error {
      display: block;
      font-size: 0.75rem;
      color: var(--error);
      margin-top: 0.3rem;
      min-height: 1rem;
    }

    .input-wrapper {
      position: relative;
    }

    .input-wrapper input {
      padding-right: 2.5rem;
    }

    .toggle-password {
      position: absolute;
      right: 2px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: #94a3b8;
      font-size: 0.85rem;
      cursor: pointer;
      padding: 0.5rem;
      line-height: 1;
    }

    .toggle-password:hover { color: var(--text-muted); }

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
      box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
      cursor: pointer;
      transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
      margin-top: 0.5rem;
    }

    .btn-signin:hover { background: #27272a; }
    .btn-signin:active { background: #18181b; box-shadow: none; }
    .btn-signin:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
    .btn-signin .btn-loader { display: none; }

    .btn-signin-success {
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
      box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
      cursor: pointer;
      text-decoration: none;
      transition: background-color 0.15s ease;
    }

    .btn-signin-success:hover { background: #27272a; }

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

    .auth-alert-success {
      background: #f0fdf4;
      color: #065f46;
      border: 1px solid rgba(16,185,129,0.15);
    }

    .auth-alert i { flex-shrink: 0; font-size: 0.9rem; }

    .footer-links {
      text-align: center;
      font-size: 0.875rem;
      margin-top: 1rem;
    }

    .footer-links a {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      color: var(--text-muted);
      font-weight: 500;
      font-size: 0.85rem;
      text-decoration: none;
      transition: color 0.2s;
    }

    .footer-links a:hover { color: var(--text-main); text-decoration: underline; }

    @media (max-width: 480px) {
      body { padding: 1rem; }
      .auth-form-card { padding: 1.75rem 1.25rem 1.5rem; }
      .form-header h2 { font-size: 1.2rem; }
    }
  </style>
</head>
<body>

  <div class="auth-form-card">

    <?php if ($success): ?>
    <div class="form-header">
      <div class="icon-wrap">
        <i class="fas fa-check-circle"></i>
      </div>
      <h2>Password Reset</h2>
      <p><?php echo htmlspecialchars($success); ?></p>
    </div>
    <a href="login.php" class="btn-signin-success">
      <span>Go to Login</span>
      <i class="fas fa-arrow-right" style="font-size:13px;"></i>
    </a>
    <?php endif; ?>

    <?php if (!$success): ?>

    <?php if ($error): ?>
    <div class="form-header">
      <div class="icon-wrap error-icon">
        <i class="fas fa-exclamation-circle"></i>
      </div>
      <h2>Reset Password</h2>
    </div>
    <div class="auth-alert auth-alert-error">
      <i class="fas fa-exclamation-circle"></i>
      <span><?php echo htmlspecialchars($error); ?></span>
    </div>
    <div class="footer-links">
      <a href="forgot-password.php"><i class="fas fa-redo"></i> Request a new link</a>
    </div>
    <div class="footer-links" style="margin-top:0.5rem;">
      <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>
    <?php else: ?>

    <div class="form-header">
      <div class="icon-wrap">
        <i class="fas fa-shield-alt"></i>
      </div>
      <h2>Reset Password</h2>
      <p>Enter your new password below</p>
      <?php if ($resetEmail): ?>
      <span class="email-badge">
        <i class="fas fa-envelope" style="font-size:0.7rem;"></i>
        <?php echo htmlspecialchars($resetEmail); ?>
      </span>
      <?php endif; ?>
    </div>

    <?php if ($resetEmail): ?>
    <form id="resetForm" action="reset-password.php" method="POST" novalidate>
      <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>" />

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

      <button type="submit" class="btn-signin" id="resetBtn">
        <span class="btn-text">Reset Password</span>
        <i class="fas fa-check-circle" style="font-size:13px;"></i>
        <span class="btn-loader"><i class="fas fa-spinner fa-spin"></i></span>
      </button>
    </form>

    <div class="footer-links" style="margin-top:1rem;">
      <a href="forgot-password.php"><i class="fas fa-redo"></i> Request a new link</a>
    </div>
    <div class="footer-links" style="margin-top:0.5rem;">
      <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>

    <?php else: ?>
    <div class="auth-alert auth-alert-error">
      <i class="fas fa-exclamation-circle"></i>
      <span>This verification link is invalid or has expired. Please request a new one.</span>
    </div>
    <div class="footer-links">
      <a href="forgot-password.php"><i class="fas fa-redo"></i> Request a new link</a>
    </div>
    <div class="footer-links" style="margin-top:0.5rem;">
      <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>

  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const form = document.getElementById('resetForm');
      if (!form) return;

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
        const icon = resetBtn.querySelector('.fa-check-circle');
        const loader = resetBtn.querySelector('.btn-loader');
        if (text) text.style.opacity = loading ? '0.6' : '1';
        if (icon) icon.style.display = loading ? 'none' : 'inline-block';
        if (loader) loader.style.display = loading ? 'inline-block' : 'none';
      }

      form.addEventListener('submit', (e) => {
        e.preventDefault();
        let valid = true;

        [['passwordGroup','passwordError'], ['confirmGroup','confirmError']].forEach(([g, e]) => {
          const el = document.getElementById(e);
          const grp = document.getElementById(g);
          if (el) el.textContent = '';
          if (grp) grp.classList.remove('has-error');
        });

        const password = passwordInput.value;
        const confirmPassword = confirmInput.value;

        if (!password) {
          document.getElementById('passwordError').textContent = 'New password is required.';
          document.getElementById('passwordGroup').classList.add('has-error');
          valid = false;
        } else if (password.length < 8) {
          document.getElementById('passwordError').textContent = 'Password must be at least 8 characters.';
          document.getElementById('passwordGroup').classList.add('has-error');
          valid = false;
        }

        if (!confirmPassword) {
          document.getElementById('confirmError').textContent = 'Please confirm your new password.';
          document.getElementById('confirmGroup').classList.add('has-error');
          valid = false;
        } else if (password !== confirmPassword) {
          document.getElementById('confirmError').textContent = 'Passwords do not match.';
          document.getElementById('confirmGroup').classList.add('has-error');
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