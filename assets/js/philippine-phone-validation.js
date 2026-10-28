(function() {
  function attach(input) {
    if (!input) return;
    input.addEventListener('input', function() {
      var digits = input.value.replace(/\D/g, '');
      input.dataset.phoneOverflow = digits.length > 11 ? 'true' : 'false';
      input.value = digits.slice(0, 11);
    });
  }

  function error(input) {
    if (!input || !input.value.trim()) return 'Phone number is required.';
    if (input.dataset.phoneOverflow === 'true') return 'Phone number cannot exceed 11 digits.';
    if (!/^09[0-9]{9}$/.test(input.value)) {
      return 'Enter an 11-digit Philippine mobile number starting with 09.';
    }
    return '';
  }

  window.DCVSPhilippinePhone = {
    attach: attach,
    error: error
  };
})();
