<?php
$error = $_GET['error'] ?? null;
$success = $_GET['success'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Forgot Password — DCVS Dasmariñas</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="icon" type="image/x-icon" href="favicon.ico?v=<?php echo (int)filemtime(__DIR__ . '/../../favicon.ico'); ?>" />
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
      margin-bottom: 1.75rem;
    }

    .form-header .icon-wrap {
      width: 48px;
      height: 48px;
      background: var(--error-bg);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 0.75rem;
    }

    .form-header .icon-wrap i {
      font-size: 1.15rem;
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

    .input-wrapper i.input-icon {
      position: absolute;
      left: 0.875rem;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
      font-size: 0.85rem;
    }

    .input-wrapper input {
      padding-left: 2.5rem;
    }

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
      background: var(--error-bg);
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

    <?php if ($error): ?>
    <div class="auth-alert auth-alert-error">
      <i class="fas fa-exclamation-circle"></i>
      <span><?php echo htmlspecialchars($error); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="auth-alert auth-alert-success">
      <i class="fas fa-check-circle"></i>
      <span><?php echo htmlspecialchars($success); ?></span>
    </div>
    <?php endif; ?>

    <div class="form-header">
      <div class="icon-wrap">
        <i class="fas fa-lock"></i>
      </div>
      <h2>Forgot Password</h2>
      <p>Enter your registered email and we'll send you a verification link.</p>
    </div>

    <form id="forgotForm" action="send-reset-code.php" method="POST" novalidate>

      <div class="form-group" id="emailGroup">
        <label for="email">Email Address</label>
        <div class="input-wrapper">
          <i class="fas fa-envelope input-icon"></i>
          <input type="email" id="email" name="email" placeholder="Enter your registered email" autocomplete="email" required />
        </div>
        <span class="field-error" id="emailError"></span>
      </div>

      <button type="submit" class="btn-signin" id="sendBtn">
        <span class="btn-text">Send Verification Link</span>
        <i class="fas fa-paper-plane" style="font-size:13px;"></i>
        <span class="btn-loader"><i class="fas fa-spinner fa-spin"></i></span>
      </button>

    </form>

    <div class="footer-links" style="margin-top:1.25rem;">
      <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>

  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const form = document.getElementById('forgotForm');
      if (!form) return;

      const emailInput = document.getElementById('email');
      const sendBtn = document.getElementById('sendBtn');

      function showError(groupId, errorId, msg) {
        const group = document.getElementById(groupId);
        const error = document.getElementById(errorId);
        if (group) group.classList.add('has-error');
        if (error) error.textContent = msg;
      }

      function clearError(groupId, errorId) {
        const group = document.getElementById(groupId);
        const error = document.getElementById(errorId);
        if (group) group.classList.remove('has-error');
        if (error) error.textContent = '';
      }

      function setLoading(loading) {
        sendBtn.disabled = loading;
        const text = sendBtn.querySelector('.btn-text');
        const icon = sendBtn.querySelector('.fa-paper-plane');
        const loader = sendBtn.querySelector('.btn-loader');
        if (text) text.style.opacity = loading ? '0.6' : '1';
        if (icon) icon.style.display = loading ? 'none' : 'inline-block';
        if (loader) loader.style.display = loading ? 'inline-block' : 'none';
      }

      emailInput.addEventListener('input', () => {
        clearError('emailGroup', 'emailError');
        const alert = document.querySelector('.auth-alert-error');
        if (alert) alert.style.display = 'none';
      });

      form.addEventListener('submit', (e) => {
        e.preventDefault();
        clearError('emailGroup', 'emailError');

        const email = emailInput.value.trim();

        if (!email) {
          showError('emailGroup', 'emailError', 'Email address is required.');
          return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
          showError('emailGroup', 'emailError', 'Enter a valid email address.');
          return;
        }

        setLoading(true);
        form.submit();
      });
    });
  </script>
</body>
</html>