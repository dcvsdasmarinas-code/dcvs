/* ============================================
   auth.js â€” Login & Register page logic
   Handles: validation, step navigation,
   password toggle, strength meter, UX states
   ============================================ */

/* ── Terms Modal ── */
window.openTermsModal = function () {
  const modal = document.getElementById('termsModal');
  if (modal) modal.classList.add('active');
};
window.closeTermsModal = function () {
  const modal = document.getElementById('termsModal');
  if (modal) modal.classList.remove('active');
};

document.addEventListener('DOMContentLoaded', () => {

  /* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     SHARED UTILITIES
  â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */

  /**
   * Show a field-level error on a form group
   */
  function showFieldError(groupId, errorId, message) {
    const group = document.getElementById(groupId);
    const error = document.getElementById(errorId);
    if (group) group.classList.add('has-error');
    if (error) error.textContent = message;
  }

  /**
   * Clear a field-level error on a form group
   */
  function clearFieldError(groupId, errorId) {
    const group = document.getElementById(groupId);
    const error = document.getElementById(errorId);
    if (group) group.classList.remove('has-error');
    if (error) error.textContent = '';
  }

  /**
   * Show the page-level alert banner
   */
  function showAlert(alertId, msgId, message, type = 'error') {
    const alert = document.getElementById(alertId);
    const msg   = document.getElementById(msgId);
    if (!alert) return;
    alert.style.display = 'flex';
    alert.className = `auth-alert auth-alert-${type}`;
    if (msg) msg.textContent = message;
    alert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  /**
   * Hide the page-level alert banner
   */
  function hideAlert(alertId) {
    const alert = document.getElementById(alertId);
    if (alert) alert.style.display = 'none';
  }

  /**
   * Toggle password visibility on an input
   */
  function setupPasswordToggle(toggleId, inputId) {
    const toggle = document.getElementById(toggleId);
    const input  = document.getElementById(inputId);
    if (!toggle || !input) return;

    toggle.addEventListener('click', () => {
      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      toggle.querySelector('i').className = isHidden ? 'fas fa-eye-slash' : 'fas fa-eye';
    });
  }

  /**
   * Set loading state on a submit button
   */
  function setLoading(btnId, loading) {
    const btn    = document.getElementById(btnId);
    if (!btn) return;
    const text   = btn.querySelector('.btn-text');
    const icon   = btn.querySelector('.btn-icon');
    const loader = btn.querySelector('.btn-loader');
    btn.disabled = loading;
    if (text)   text.style.opacity  = loading ? '0.6' : '1';
    if (icon)   icon.style.display  = loading ? 'none' : 'inline-block';
    if (loader) loader.style.display = loading ? 'inline-block' : 'none';
  }

  /* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     LOGIN PAGE
  â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
  const loginForm = document.getElementById('loginForm');
  if (loginForm) {

    // Password toggle
    setupPasswordToggle('togglePassword', 'password');

    // Real-time clear error on input
    document.getElementById('email')?.addEventListener('input', () => {
      clearFieldError('emailGroup', 'emailError');
      hideAlert('loginAlert');
    });
    document.getElementById('password')?.addEventListener('input', () => {
      clearFieldError('passwordGroup', 'passwordError');
      hideAlert('loginAlert');
    });

    // Form submit validation
    loginForm.addEventListener('submit', (e) => {
      e.preventDefault();
      let valid = true;

      const email    = document.getElementById('email').value.trim();
      const password = document.getElementById('password').value;

      // Clear previous errors
      clearFieldError('emailGroup', 'emailError');
      clearFieldError('passwordGroup', 'passwordError');
      hideAlert('loginAlert');

      // Validate email
      if (!email) {
        showFieldError('emailGroup', 'emailError', 'Email address is required.');
        valid = false;
      } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showFieldError('emailGroup', 'emailError', 'Please enter a valid email address.');
        valid = false;
      }

      // Validate password
      if (!password) {
        showFieldError('passwordGroup', 'passwordError', 'Password is required.');
        valid = false;
      }

      if (!valid) {
        showAlert('loginAlert', 'loginAlertMsg', 'Please fix the errors below.');
        return;
      }

      // Show loading state
      setLoading('loginBtn', true);

      // Submit the form to PHP
      loginForm.submit();
    });
  }


  /* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     REGISTER PAGE
  â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
  const registerForm = document.getElementById('registerForm');
  if (registerForm) {

    // Password toggles
    setupPasswordToggle('toggleRegPassword', 'regPassword');
    setupPasswordToggle('toggleConfirmPassword', 'confirmPassword');

    /* â”€â”€ Step Navigation State â”€â”€ */
    let currentStep = 1;
    const totalSteps = 3;

    function goToStep(step) {
      // Hide all steps
      document.querySelectorAll('.form-step').forEach(s => s.classList.remove('active'));
      // Show target
      document.getElementById(`step${step}`)?.classList.add('active');

      // Update dots
      document.querySelectorAll('.step-dot').forEach(dot => {
        const dotStep = parseInt(dot.dataset.step);
        dot.classList.remove('active', 'completed');
        if (dotStep === step)   dot.classList.add('active');
        if (dotStep < step)     dot.classList.add('completed');
      });

      // Update lines
      document.querySelectorAll('.step-line').forEach((line, idx) => {
        line.classList.toggle('filled', idx < step - 1);
      });

      currentStep = step;

      // Update register button state when step 3 becomes active
      if (step === 3) {
        const cb = document.getElementById('agreeTerms');
        const btn = document.getElementById('registerBtn');
        if (cb && btn) btn.disabled = !cb.checked;
      }
    }

    /* â”€â”€ Step 1 â†’ Step 2 â”€â”€ */
    document.getElementById('nextToStep2')?.addEventListener('click', () => {
      if (validateStep1()) goToStep(2);
    });

    /* â”€â”€ Step 2 â†’ Step 3 â”€â”€ */
    document.getElementById('nextToStep3')?.addEventListener('click', () => {
      if (validateStep2()) goToStep(3);
    });

    /* â”€â”€ Step 2 â†’ Step 1 â”€â”€ */
    document.getElementById('backToStep1')?.addEventListener('click', () => goToStep(1));

    /* â”€â”€ Step 3 â†’ Step 2 â”€â”€ */
    document.getElementById('backToStep2')?.addEventListener('click', () => goToStep(2));


    /* â”€â”€ Step Validators â”€â”€ */

    function validateStep1() {
      let valid = true;
      hideAlert('registerAlert');

      const firstName = document.getElementById('firstName')?.value.trim();
      const lastName  = document.getElementById('lastName')?.value.trim();
      const phone     = document.getElementById('phone')?.value.trim();
      const birthdate = document.getElementById('birthdate')?.value;
      const gender    = document.getElementById('gender')?.value;

      clearFieldError('firstNameGroup',  'firstNameError');
      clearFieldError('lastNameGroup',   'lastNameError');
      clearFieldError('phoneGroup',      'phoneError');
      clearFieldError('birthdateGroup',  'birthdateError');
      clearFieldError('genderGroup',     'genderError');

      if (!firstName) {
        showFieldError('firstNameGroup', 'firstNameError', 'First name is required.');
        valid = false;
      }

      if (!lastName) {
        showFieldError('lastNameGroup', 'lastNameError', 'Last name is required.');
        valid = false;
      }

      if (!phone) {
        showFieldError('phoneGroup', 'phoneError', 'Phone number is required.');
        valid = false;
      } else if (!/^(\+63|0)[0-9]{10}$/.test(phone.replace(/\s/g, ''))) {
        showFieldError('phoneGroup', 'phoneError', 'Enter a valid PH number (e.g. 09XX XXX XXXX).');
        valid = false;
      }

      if (!birthdate) {
        showFieldError('birthdateGroup', 'birthdateError', 'Date of birth is required.');
        valid = false;
      } else {
        const dob = new Date(birthdate);
        const today = new Date();
        const age = today.getFullYear() - dob.getFullYear();
        if (age < 18) {
          showFieldError('birthdateGroup', 'birthdateError', 'You must be at least 18 years old to register.');
          valid = false;
        }
      }

      if (!gender) {
        showFieldError('genderGroup', 'genderError', 'Please select your gender.');
        valid = false;
      }

      if (!valid) showAlert('registerAlert', 'registerAlertMsg', 'Please complete all required fields.');
      return valid;
    }

    function validateStep2() {
      let valid = true;
      hideAlert('registerAlert');

      const street   = document.getElementById('street')?.value.trim();
      const barangay = document.getElementById('barangay')?.value;

      clearFieldError('streetGroup',   'streetError');
      clearFieldError('barangayGroup', 'barangayError');

      if (!street) {
        showFieldError('streetGroup', 'streetError', 'Street address is required.');
        valid = false;
      }

      if (!barangay) {
        showFieldError('barangayGroup', 'barangayError', 'Please select your barangay.');
        valid = false;
      }

      if (!valid) showAlert('registerAlert', 'registerAlertMsg', 'Please complete all required fields.');
      return valid;
    }

    function validateStep3() {
      let valid = true;
      hideAlert('registerAlert');

      const username       = document.getElementById('regUsername')?.value.trim();
      const email           = document.getElementById('regEmail')?.value.trim();
      const password        = document.getElementById('regPassword')?.value;
      const confirmPassword = document.getElementById('confirmPassword')?.value;
      const agreeTerms      = document.getElementById('agreeTerms')?.checked;

      clearFieldError('regUsernameGroup',    'regUsernameError');
      clearFieldError('regEmailGroup',       'regEmailError');
      clearFieldError('regPasswordGroup',    'regPasswordError');
      clearFieldError('confirmPasswordGroup','confirmPasswordError');

      if (!username) {
        showFieldError('regUsernameGroup', 'regUsernameError', 'Username is required.');
        valid = false;
      } else if (username.length < 3) {
        showFieldError('regUsernameGroup', 'regUsernameError', 'Username must be at least 3 characters.');
        valid = false;
      } else if (!/^[a-zA-Z0-9_]+$/.test(username)) {
        showFieldError('regUsernameGroup', 'regUsernameError', 'Username can only contain letters, numbers, and underscores.');
        valid = false;
      }

      if (!email) {
        showFieldError('regEmailGroup', 'regEmailError', 'Email address is required.');
        valid = false;
      } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showFieldError('regEmailGroup', 'regEmailError', 'Please enter a valid email address.');
        valid = false;
      }

      if (!password) {
        showFieldError('regPasswordGroup', 'regPasswordError', 'Password is required.');
        valid = false;
      } else if (password.length < 8) {
        showFieldError('regPasswordGroup', 'regPasswordError', 'Password must be at least 8 characters.');
        valid = false;
      } else if (!/[A-Z]/.test(password)) {
        showFieldError('regPasswordGroup', 'regPasswordError', 'Password must contain at least one uppercase letter.');
        valid = false;
      } else if (!/[a-z]/.test(password)) {
        showFieldError('regPasswordGroup', 'regPasswordError', 'Password must contain at least one lowercase letter.');
        valid = false;
      } else if (!/[0-9]/.test(password)) {
        showFieldError('regPasswordGroup', 'regPasswordError', 'Password must contain at least one number.');
        valid = false;
      } else if (!/[^A-Za-z0-9]/.test(password)) {
        showFieldError('regPasswordGroup', 'regPasswordError', 'Password must contain at least one special character.');
        valid = false;
      }

      if (!confirmPassword) {
        showFieldError('confirmPasswordGroup', 'confirmPasswordError', 'Please confirm your password.');
        valid = false;
      } else if (password !== confirmPassword) {
        showFieldError('confirmPasswordGroup', 'confirmPasswordError', 'Passwords do not match.');
        valid = false;
      }

      if (!agreeTerms) {
        const termsErr = document.getElementById('termsError');
        if (termsErr) termsErr.textContent = 'You must agree to the Terms and Conditions before creating an account.';
        valid = false;
      } else {
        const termsErr = document.getElementById('termsError');
        if (termsErr) termsErr.textContent = '';
      }

      if (!valid) showAlert('registerAlert', 'registerAlertMsg', 'Please fix the errors below.');
      return valid;
    }


    /* â”€â”€ Password Strength Meter â”€â”€ */
    const regPassword    = document.getElementById('regPassword');
    const passwordStrength = document.getElementById('passwordStrength');
    const strengthFill   = document.getElementById('strengthFill');
    const strengthLabel  = document.getElementById('strengthLabel');

    regPassword?.addEventListener('input', () => {
      const val = regPassword.value;
      clearFieldError('regPasswordGroup', 'regPasswordError');

      if (!val) {
        if (passwordStrength) passwordStrength.style.display = 'none';
        return;
      }

      if (passwordStrength) passwordStrength.style.display = 'flex';

      let score = 0;
      if (val.length >= 8)           score++;
      if (/[A-Z]/.test(val))         score++;
      if (/[a-z]/.test(val))         score++;
      if (/[0-9]/.test(val))         score++;
      if (/[^A-Za-z0-9]/.test(val))  score++;

      const levels = ['', 'weak', 'weak', 'fair', 'good', 'strong'];
      const labels = ['', 'Weak', 'Weak', 'Fair', 'Good', 'Strong'];
      const colors = ['', '#ef4444', '#ef4444', '#f97316', '#eab308', '#0e8131'];

      if (strengthFill) {
        strengthFill.className = `strength-fill ${levels[score]}`;
        strengthFill.style.background = colors[score];
      }
      if (strengthLabel) {
        strengthLabel.textContent = labels[score];
        strengthLabel.style.color = colors[score];
      }
    });

    /* â”€â”€ Register Form Submit â”€â”€ */
    registerForm.addEventListener('submit', (e) => {
      e.preventDefault();
      if (!validateStep3()) return;

      setLoading('registerBtn', true);

      // Submit the form to PHP
      registerForm.submit();
    });

    /* ── Terms Checkbox & Modal ── */
    const agreeCb = document.getElementById('agreeTerms');
    const regBtn = document.getElementById('registerBtn');

    if (agreeCb && regBtn) {
      // Disable register button initially if checkbox is unchecked
      regBtn.disabled = !agreeCb.checked;
      regBtn.style.opacity = agreeCb.checked ? '1' : '0.5';
      regBtn.style.cursor = agreeCb.checked ? 'pointer' : 'not-allowed';

      agreeCb.addEventListener('change', function () {
        regBtn.disabled = !this.checked;
        regBtn.style.opacity = this.checked ? '1' : '0.5';
        regBtn.style.cursor = this.checked ? 'pointer' : 'not-allowed';
        // Clear error when checkbox is checked
        if (this.checked) {
          const termsErr = document.getElementById('termsError');
          if (termsErr) termsErr.textContent = '';
        }
      });
    }

    // "I Agree" button handler
    const agreeBtn = document.getElementById('agreeTermsBtn');
    if (agreeBtn) {
      agreeBtn.addEventListener('click', function () {
        const cb = document.getElementById('agreeTerms');
        if (cb) cb.checked = true;
        cb?.dispatchEvent(new Event('change'));
        closeTermsModal();
      });
    }

    // Close modal on overlay click
    const modal = document.getElementById('termsModal');
    modal?.addEventListener('click', function (e) {
      if (e.target === this) closeTermsModal();
    });
  }

});
