<?php
$token = $_GET['token'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Check Your Email — DCVS Dasmariñas</title>
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
      text-align: center;
    }

    .form-header {
      margin-bottom: 1rem;
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

    .form-header p strong { color: var(--text-main); }

    .spinner {
      width: 36px; height: 36px; border: 3px solid #e2e8f0; border-top-color: var(--text-main);
      border-radius: 50%; animation: spin 0.8s linear infinite;
      margin: 1rem auto 0;
    }

    @keyframes spin { to { transform: rotate(360deg); } }

    .waiting-label {
      margin-top: 0.75rem; font-size: 0.85rem; color: #0a7a3b; font-weight: 600;
    }

    .success-state .spinner { border-color: #bbf7d0; border-top-color: #0a7a3b; }
    .success-state h2 { color: #065f46; }

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

    .hidden { display: none !important; }

    @media (max-width: 480px) {
      body { padding: 1rem; }
      .auth-form-card {
        padding: 0;
        background: transparent;
        border: 0;
        border-radius: 0;
        box-shadow: none;
      }
      .form-header h2 { font-size: 1.2rem; }
    }
  </style>
</head>
<body>

  <!-- STATE: Waiting -->
  <div class="auth-form-card" id="stateWaiting">
    <div class="form-header">
      <div class="icon-wrap" id="waitingIcon">
        <i class="fas fa-envelope-open-text"></i>
      </div>
      <h2 id="stateTitle">Check Your Email</h2>
      <p id="stateMsg">We sent a verification link to your registered email address.<br><strong>Open the email and confirm that it's you.</strong></p>
    </div>
    <div class="spinner" id="spinner"></div>
    <div class="waiting-label" id="waitingLabel">Waiting for verification...</div>
  </div>

  <!-- STATE: Verified -->
  <div class="auth-form-card hidden success-state" id="stateVerified">
    <div class="form-header">
      <div class="icon-wrap">
        <i class="fas fa-check-circle"></i>
      </div>
      <h2>Verification successful</h2>
      <p>Preparing your password reset...</p>
    </div>
    <div class="spinner" style="margin-top:0.5rem;"></div>
  </div>

  <!-- STATE: Error -->
  <div class="auth-form-card hidden" id="stateError">
    <div class="form-header">
      <div class="icon-wrap error-icon">
        <i class="fas fa-exclamation-triangle"></i>
      </div>
      <h2 id="errorTitle">Link expired or invalid</h2>
      <p id="errorMsg">This verification link is invalid or has already been used.</p>
    </div>
    <div class="footer-links">
      <a href="forgot-password.php"><i class="fas fa-redo"></i> Request a new link</a>
    </div>
    <div class="footer-links" style="margin-top:0.5rem;">
      <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>
  </div>

  <script>
    (function() {
      var token = <?php echo json_encode($token); ?>;

      if (!token) {
        showCard('stateError');
        return;
      }

      showCard('stateWaiting');

      var pollInterval = null;
      var maxAttempts = 900;
      var attempts = 0;

      function showCard(id) {
        document.querySelectorAll('.auth-form-card').forEach(function(c) { c.classList.add('hidden'); });
        document.getElementById(id).classList.remove('hidden');
      }

      function poll() {
        attempts++;
        if (attempts > maxAttempts) {
          stopPolling();
          document.getElementById('errorTitle').textContent = 'Timed Out';
          document.getElementById('errorMsg').textContent = 'Verification timed out after 15 minutes. Please request a new link.';
          showCard('stateError');
          return;
        }

        fetch('verify-status.php?token=' + encodeURIComponent(token))
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (data.verified) {
              stopPolling();
              showCard('stateVerified');
              setTimeout(function() {
                window.location.href = 'reset-password.php?token=' + encodeURIComponent(token);
              }, 2000);
            } else if (data.status === 'expired') {
              stopPolling();
              document.getElementById('errorTitle').textContent = 'Link Expired';
              document.getElementById('errorMsg').textContent = 'This verification link has expired. Please request a new one.';
              showCard('stateError');
            } else if (data.status === 'used') {
              stopPolling();
              document.getElementById('errorTitle').textContent = 'Already Used';
              document.getElementById('errorMsg').textContent = 'This verification link has already been used.';
              showCard('stateError');
            } else if (data.status === 'invalid') {
              stopPolling();
              document.getElementById('errorTitle').textContent = 'Invalid Link';
              document.getElementById('errorMsg').textContent = 'This verification link is invalid.';
              showCard('stateError');
            }
          })
          .catch(function() {
            // Network error — keep polling
          });
      }

      function stopPolling() {
        if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
      }

      pollInterval = setInterval(poll, 2000);
      poll();
    })();
  </script>
</body>
</html>