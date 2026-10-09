<?php
require_once 'db.php';

$token = trim($_GET['token'] ?? '');
$valid = false;
$alreadyVerified = false;

if (!empty($token)) {
    $stmt = $conn->prepare("SELECT id, verified FROM password_resets WHERE token = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $valid = true;
        $alreadyVerified = (bool)$row['verified'];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verify Request — DCVS Dasmariñas</title>
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
      text-align: center;
    }

    .form-header {
      margin-bottom: 1.5rem;
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

    .form-header .icon-wrap.success-icon {
      background: #f0fdf4;
    }

    .form-header .icon-wrap.success-icon i {
      color: #0a7a3b;
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
      margin-top: 1.25rem;
    }

    .btn-signin:hover { background: #27272a; }
    .btn-signin:active { background: #18181b; box-shadow: none; }
    .btn-signin:disabled { opacity: 0.5; cursor: not-allowed; pointer-events: none; }
    .btn-signin .btn-loader { display: none; }

    .btn-link {
      display: inline-block;
      margin-top: 0.75rem;
      font-family: 'Inter', sans-serif;
      font-size: 0.85rem;
      font-weight: 500;
      color: var(--text-muted);
      text-decoration: none;
      cursor: pointer;
      background: none;
      border: none;
    }

    .btn-link:hover { color: var(--text-main); text-decoration: underline; }

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

    .check-icon { font-size: 2.5rem; color: #0a7a3b; margin-bottom: 0.75rem; }

    .hidden { display: none !important; }

    .spinner-sm {
      width: 20px; height: 20px; border: 3px solid #e2e8f0; border-top-color: var(--text-main);
      border-radius: 50%; animation: spin 0.7s linear infinite; margin: 0 auto;
    }

    @keyframes spin { to { transform: rotate(360deg); } }

    .spinner-lg {
      width: 36px; height: 36px; border: 3px solid #e2e8f0; border-top-color: var(--text-main);
      border-radius: 50%; animation: spin 0.7s linear infinite; margin: 0 auto 1rem;
    }

    @media (max-width: 480px) {
      body { padding: 1rem; }
      .auth-form-card { padding: 1.75rem 1.25rem 1.5rem; }
      .form-header h2 { font-size: 1.2rem; }
    }
  </style>
</head>
<body>

  <!-- STATE: Prompt -->
  <div class="auth-form-card" id="statePrompt">
    <div class="form-header">
      <div class="icon-wrap" id="promptIcon">
        <i class="fas fa-shield-alt"></i>
      </div>
      <h2>Verify Your Request</h2>
      <p>We received a request to reset your DCVS account password.</p>
      <p style="margin-top:0.5rem;"><strong>Are you trying to reset your password?</strong></p>
    </div>
    <button class="btn-signin" id="verifyBtn" onclick="doVerify()">
      <span class="btn-text">Yes, It's Me</span>
      <i class="fas fa-check-circle" style="font-size:13px;"></i>
      <span class="btn-loader"><div class="spinner-sm" style="border-top-color:#fafafa;width:16px;height:16px;border-width:2px;"></div></span>
    </button>
    <button class="btn-link" onclick="window.location.href='login.php'">Cancel</button>
  </div>

  <!-- STATE: Loading -->
  <div class="auth-form-card hidden" id="stateLoading">
    <div class="spinner-lg"></div>
    <div class="form-header" style="margin-bottom:0;">
      <h2>Verifying...</h2>
      <p>Please wait while we confirm your request.</p>
    </div>
  </div>

  <!-- STATE: Success -->
  <div class="auth-form-card hidden" id="stateSuccess">
    <div class="form-header">
      <div class="icon-wrap success-icon">
        <i class="fas fa-check-circle"></i>
      </div>
      <h2>Verified</h2>
      <p>Your request has been verified successfully.</p>
      <p style="margin-top:0.5rem;"><strong>Continue on the device where you started the password reset.</strong></p>
    </div>
  </div>

  <!-- STATE: Error -->
  <div class="auth-form-card hidden" id="stateError">
    <div class="form-header">
      <div class="icon-wrap">
        <i class="fas fa-exclamation-triangle"></i>
      </div>
      <h2 id="errorTitle">Link expired or invalid</h2>
      <p id="errorMsg">This verification link is invalid or has already been used. Please request a new one.</p>
    </div>
    <div class="footer-links">
      <a href="forgot-password.php"><i class="fas fa-redo"></i> Request a new link</a>
    </div>
    <div class="footer-links" style="margin-top:0.5rem;">
      <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
    </div>
  </div>

  <!-- STATE: Already verified -->
  <div class="auth-form-card hidden" id="stateAlready">
    <div class="form-header">
      <div class="icon-wrap success-icon">
        <i class="fas fa-check-circle"></i>
      </div>
      <h2>Already Verified</h2>
      <p>This request was already verified. Continue on the device where you started the password reset.</p>
    </div>
  </div>

  <script>
    var token = <?php echo json_encode($token); ?>;
    var valid = <?php echo $valid ? 'true' : 'false'; ?>;
    var alreadyVerified = <?php echo $alreadyVerified ? 'true' : 'false'; ?>;

    function show(id) {
      document.querySelectorAll('.auth-form-card').forEach(function(c) { c.classList.add('hidden'); });
      document.getElementById(id).classList.remove('hidden');
    }

    if (!valid) {
      show('stateError');
    } else if (alreadyVerified) {
      show('stateAlready');
    } else {
      show('statePrompt');
    }

    function doVerify() {
      var btn = document.getElementById('verifyBtn');
      btn.disabled = true;
      btn.querySelector('.btn-text').style.opacity = '0.5';
      btn.querySelector('.fa-check-circle').style.display = 'none';
      btn.querySelector('.btn-loader').style.display = 'inline-block';

      fetch('verify-action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: token })
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.status === 'verified' || data.message) {
          show('stateLoading');
          setTimeout(function() { show('stateSuccess'); }, 1500);
        } else if (data.status === 'used') {
          document.getElementById('errorTitle').textContent = 'Already Used';
          document.getElementById('errorMsg').textContent = 'This verification link has already been used. Please request a new one.';
          show('stateError');
        } else if (data.status === 'expired') {
          document.getElementById('errorTitle').textContent = 'Link Expired';
          document.getElementById('errorMsg').textContent = 'This verification link has expired. Please request a new one.';
          show('stateError');
        } else {
          document.getElementById('errorTitle').textContent = 'Verification Failed';
          document.getElementById('errorMsg').textContent = 'Something went wrong. Please try again.';
          show('stateError');
        }
      })
      .catch(function() {
        document.getElementById('errorTitle').textContent = 'Connection Error';
        document.getElementById('errorMsg').textContent = 'Could not connect to the server. Please check your connection and try again.';
        show('stateError');
      });
    }
  </script>
</body>
</html>