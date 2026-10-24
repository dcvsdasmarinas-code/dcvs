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

  function showRegistrationValidationSummary(errorIds) {
    const messages = errorIds
      .map(id => document.getElementById(id)?.textContent.trim())
      .filter(Boolean);
    const missing = messages.filter(message =>
      /\brequired\b|\bplease select\b|\bplease confirm\b|\bmust agree\b/i.test(message)
    );
    const revise = messages.filter(message => !missing.includes(message));
    const summary = [];

    if (missing.length) summary.push(`Complete the required items: ${missing.join(' ')}`);
    if (revise.length) summary.push(`Please revise: ${revise.join(' ')}`);

    showAlert(
      'registerAlert',
      'registerAlertMsg',
      summary.join(' ') || 'Review the highlighted fields and try again.'
    );
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
        showAlert('loginAlert', 'loginAlertMsg', 'Unable to sign in. Please check your credentials and try again.');
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
    const phoneInput = document.getElementById('phone');
    window.DCVSPhilippinePhone?.attach(phoneInput);
    phoneInput?.addEventListener('input', () => {
      if (phoneInput.dataset.phoneTouched !== 'true' && phoneInput.dataset.phoneOverflow !== 'true') {
        clearFieldError('phoneGroup', 'phoneError');
        return;
      }
      const phoneError = window.DCVSPhilippinePhone.error(phoneInput);
      if (phoneError) showFieldError('phoneGroup', 'phoneError', phoneError);
      else clearFieldError('phoneGroup', 'phoneError');
    });
    phoneInput?.addEventListener('blur', () => {
      phoneInput.dataset.phoneTouched = 'true';
      const phoneError = window.DCVSPhilippinePhone.error(phoneInput);
      if (phoneError) showFieldError('phoneGroup', 'phoneError', phoneError);
      else clearFieldError('phoneGroup', 'phoneError');
    });

    /* â”€â”€ Step Navigation State â”€â”€ */
    let currentStep = 1;
    const totalSteps = 3;
    const registrationDraftKey = `dcvs-registration-draft:${window.location.pathname}`;

    function saveRegistrationDraft() {
      const draft = { step: currentStep, savedAt: Date.now(), fields: {} };
      registerForm.querySelectorAll('input[name], select[name], textarea[name]').forEach(field => {
        if (field.type === 'password' || field.type === 'hidden' || field.type === 'file' ||
            field.type === 'submit' || field.type === 'button' || field.name === 'csrf_token') {
          return;
        }

        draft.fields[field.name] = field.type === 'checkbox' || field.type === 'radio'
          ? { checked: field.checked, value: field.value }
          : { value: field.value };
        if (field.name === 'phone') {
          draft.fields[field.name].overflow = field.dataset.phoneOverflow === 'true';
        }
      });

      try {
        window.sessionStorage.setItem(registrationDraftKey, JSON.stringify(draft));
      } catch (error) {
        console.warn('Unable to save registration draft in this tab.', error);
      }
    }

    function restoreRegistrationDraft() {
      let draft;
      try {
        draft = JSON.parse(window.sessionStorage.getItem(registrationDraftKey) || 'null');
      } catch (error) {
        console.warn('Unable to read registration draft in this tab.', error);
        return;
      }
      if (!draft) return;
      if (Date.now() - Number(draft.savedAt) > 24 * 60 * 60 * 1000) {
        try {
          window.sessionStorage.removeItem(registrationDraftKey);
        } catch (error) {
          console.warn('Unable to clear expired registration draft.', error);
        }
        return;
      }

      registerForm.querySelectorAll('input[name], select[name], textarea[name]').forEach(field => {
        if (field.type === 'password' || field.type === 'hidden' || field.type === 'file' ||
            field.type === 'submit' || field.type === 'button' || field.name === 'csrf_token') {
          return;
        }

        const savedField = draft.fields && draft.fields[field.name];
        if (!savedField) return;
        if (field.type === 'checkbox' || field.type === 'radio') {
          field.checked = savedField.checked === true;
        } else {
          field.value = savedField.value || '';
          field.dispatchEvent(new Event('input', { bubbles: true }));
          field.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (field.name === 'phone') {
          field.dataset.phoneOverflow = savedField.overflow ? 'true' : 'false';
        }
      });

      const savedStep = Number(draft.step);
      if (Number.isInteger(savedStep) && savedStep >= 1 && savedStep <= totalSteps) {
        goToStep(savedStep);
      }
    }

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
      saveRegistrationDraft();

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

      phoneInput.dataset.phoneTouched = 'true';
      const phoneValidationMessage = window.DCVSPhilippinePhone.error(phoneInput);
      if (phoneValidationMessage) {
        showFieldError('phoneGroup', 'phoneError', phoneValidationMessage);
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

      if (!valid) {
        showRegistrationValidationSummary([
          'firstNameError', 'lastNameError', 'phoneError', 'birthdateError', 'genderError'
        ]);
      }
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

      if (!valid) showRegistrationValidationSummary(['streetError', 'barangayError']);
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
      const registrationPhone = document.getElementById('phone');
      const passwordRules = passwordRequirements(password || '');

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

      if (registrationPhone) {
        registrationPhone.dataset.phoneTouched = 'true';
        const phoneValidationMessage = window.DCVSPhilippinePhone.error(registrationPhone);
        if (phoneValidationMessage) {
          showFieldError('phoneGroup', 'phoneError', phoneValidationMessage);
          valid = false;
        } else {
          clearFieldError('phoneGroup', 'phoneError');
        }
      }

      if (!password) {
        showFieldError('regPasswordGroup', 'regPasswordError', 'Password is required.');
        valid = false;
      } else if (!passwordRules.length) {
        showFieldError('regPasswordGroup', 'regPasswordError', 'Password must be at least 8 characters.');
        valid = false;
      } else if (!passwordRules.uppercase) {
        showFieldError('regPasswordGroup', 'regPasswordError', 'Password must contain at least one uppercase letter.');
        valid = false;
      } else if (!passwordRules.lowercase) {
        showFieldError('regPasswordGroup', 'regPasswordError', 'Password must contain at least one lowercase letter.');
        valid = false;
      } else if (!passwordRules.number) {
        showFieldError('regPasswordGroup', 'regPasswordError', 'Password must contain at least one number.');
        valid = false;
      } else if (!passwordRules.special) {
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

      if (!valid) {
        showRegistrationValidationSummary([
          'regUsernameError', 'regEmailError', 'phoneError', 'regPasswordError',
          'confirmPasswordError', 'termsError'
        ]);
      }
      return valid;
    }


    /* â”€â”€ Password Strength Meter â”€â”€ */
    const regPassword    = document.getElementById('regPassword');
    const passwordStrength = document.getElementById('passwordStrength');
    const strengthFill   = document.getElementById('strengthFill');
    const strengthLabel  = document.getElementById('strengthLabel');

    function passwordRequirements(value) {
      return {
        length: Array.from(value).length >= 8,
        uppercase: /[A-Z]/.test(value),
        lowercase: /[a-z]/.test(value),
        number: /[0-9]/.test(value),
        special: /[^A-Za-z0-9]/.test(value)
      };
    }

    regPassword?.addEventListener('input', () => {
      const val = regPassword.value;
      const requirements = passwordRequirements(val);
      const firstMissing = Object.keys(requirements).find(key => !requirements[key]);
      if (!val || !firstMissing) clearFieldError('regPasswordGroup', 'regPasswordError');

      if (!val) {
        if (passwordStrength) passwordStrength.style.display = 'none';
        document.querySelectorAll('#passwordRequirements [data-password-rule]').forEach(item => {
          item.classList.remove('is-met');
          item.setAttribute('aria-checked', 'false');
        });
        return;
      }

      if (passwordStrength) passwordStrength.style.display = 'flex';

      const score = Object.values(requirements).filter(Boolean).length;
      const strength = score === 5 ? 'strong' : (score >= 3 ? 'medium' : 'weak');
      const label = strength === 'strong' ? 'Strong' : (strength === 'medium' ? 'Medium' : 'Weak');
      const color = strength === 'strong' ? '#0e8131' : (strength === 'medium' ? '#f59e0b' : '#ef4444');
      const requirementItems = document.querySelectorAll('#passwordRequirements [data-password-rule]');
      requirementItems.forEach(item => {
        const isMet = Boolean(requirements[item.dataset.passwordRule]);
        item.classList.toggle('is-met', isMet);
        item.setAttribute('aria-checked', isMet ? 'true' : 'false');
      });

      if (strengthFill) {
        strengthFill.className = `strength-fill ${strength}`;
        strengthFill.style.background = color;
      }
      if (strengthLabel) {
        strengthLabel.textContent = label;
        strengthLabel.style.color = color;
      }
    });

    /* â”€â”€ Register Form Submit â”€â”€ */
    registerForm.addEventListener('submit', saveRegistrationDraft);
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

    restoreRegistrationDraft();
    registerForm.addEventListener('input', saveRegistrationDraft);
    registerForm.addEventListener('change', saveRegistrationDraft);
  }

});
