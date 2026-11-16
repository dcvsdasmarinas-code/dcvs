(function () {
  function initializeScrollBehavior() {
    var header = document.querySelector('.site-header');
    if (!header) return;

    var navLinks = header.querySelector('.nav-links');
    var lastY = window.scrollY;
    var minimumScroll = 120;
    var directionThreshold = 6;

    function updateVisibility(scrollPosition) {
      var position = Number.isFinite(scrollPosition) ? scrollPosition : window.scrollY;
      var currentY = Math.max(0, position);
      var menuOpen = navLinks && navLinks.classList.contains('open');
      var delta = currentY - lastY;

      if (menuOpen || currentY < minimumScroll) {
        header.classList.remove('nav-header-hidden');
        lastY = currentY;
        return;
      }

      if (Math.abs(delta) >= directionThreshold) {
        header.classList.toggle('nav-header-hidden', delta > 0);
        lastY = currentY;
      }
    }

    window.addEventListener('scroll', function () {
      updateVisibility(window.scrollY);
    }, { passive: true });

    if (window.lenis && typeof window.lenis.on === 'function') {
      window.lenis.on('scroll', function (event) {
        updateVisibility(event && typeof event.scroll === 'number' ? event.scroll : window.scrollY);
      });
    }

    if (navLinks) {
      navLinks.addEventListener('transitionend', function () {
        updateVisibility(window.scrollY);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeScrollBehavior, { once: true });
  } else {
    initializeScrollBehavior();
  }
})();
