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

    .card {
      background: var(--surface);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      width: 100%;
      max-width: 420px;
      padding: 2.25rem 2rem 2rem;
    }

    .header {
      text-align: center;
      margin-bottom: 1.75rem;
    }

    .header .icon-wrap {
      width: 48px;
      height: 48px;
      background: var(--green-light);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 0.75rem;
    }

    .header .icon-wrap i {
      font-size: 1.25rem;
      color: var(--green);
    }

    .header h2 {
      font-family: 'Montserrat', sans-serif;
      font-size: 1.35rem;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 0.25rem;
    }

    .header p {
      font-size: 0.875rem;
      color: var(--text-muted);
      line-height: 1.5;
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

    .input-wrapper {
      position: relative;
    }

    .input-wrapper i.input-icon {
      position: absolute;
      left: 1rem;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-light);
      font-size: 0.95rem;
    }

    .input-wrapper input {
      padding-left: 2.75rem;
    }

    .btn {
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

    .btn:hover { background: #1e293b; }
    .btn:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }

    .btn .btn-loader { display: none; }

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

    @media (max-width: 480px) {
      body { padding: 1rem; }
      .card { padding: 1.75rem 1.25rem 1.5rem; }
      .header h2 { font-size: 1.2rem; }
    }
  </style>
</head>
<body>

  <div class="card">

    <?php if ($error): ?>
    <div class="alert alert-error">
      <i class="fas fa-exclamation-circle"></i>
      <span><?php echo htmlspecialchars($error); ?></span>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success">
      <i class="fas fa-check-circle"></i>
      <span><?php echo htmlspecialchars($success); ?></span>
    </div>
    <?php endif; ?>

    <div class="header">
      <div class="icon-wrap">
        <i class="fas fa-lock"></i>
      </div>
      <h2>Forgot Password</h2>
      <p>Enter your registered email and we'll send you a verification code.</p>
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

      <button type="submit" class="btn" id="sendBtn">
        <span class="btn-text">Send Verification Code</span>
        <i class="fas fa-paper-plane"></i>
        <span class="btn-loader"><i class="fas fa-spinner fa-spin"></i></span>
      </button>

    </form>

    <div class="divider">or</div>

    <div class="footer-links">
      <a href="login.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
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
        const icon = sendBtn.querySelector('.btn-icon');
        const loader = sendBtn.querySelector('.btn-loader');
        if (text) text.style.opacity = loading ? '0.6' : '1';
        if (icon) icon.style.display = loading ? 'none' : 'inline-block';
        if (loader) loader.style.display = loading ? 'inline-block' : 'none';
      }

      emailInput.addEventListener('input', () => {
        clearError('emailGroup', 'emailError');
        const alert = document.querySelector('.alert-error');
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