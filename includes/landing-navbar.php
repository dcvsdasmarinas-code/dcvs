<header class="site-header">
  <nav class="navbar">
    <a href="<?php echo $homePage; ?>"><img src="uploads/site/DCVS LOGO.png" alt="DCVS Logo" class="brand-logo" style="height: 50px; width: auto;"></a>
    <button class="nav-toggle" aria-label="Toggle navigation">
      <span></span><span></span><span></span>
    </button>
    <ul class="nav-links">
      <li><a href="<?php echo $homePage; ?>" class="<?php echo $currentPage === 'home' ? 'active' : ''; ?>">Home</a></li>
      <li class="nav-item">
        <a href="javascript:void(0)" class="dropdown-toggle <?php echo in_array($currentPage, ['updates','geo-map']) ? 'active' : ''; ?>">Updates <span class="arrow">▾</span></a>
        <div class="dropdown-menu">
          <a href="announcements.php">Public Service Updates</a>
          <a href="geo-map-landing.php">Geo-Map</a>
        </div>
      </li>
      <li class="nav-item">
        <a href="javascript:void(0)" class="dropdown-toggle">About <span class="arrow">▾</span></a>
        <div class="dropdown-menu">
          <a href="about.php">About Us</a>
          <a href="faq.php">FAQ</a>
          <a href="contact.php">Contact Us</a>
        </div>
      </li>
      <li><a href="adoption.php">Adoption</a></li>
    </ul>
    <a href="login.php" class="nav-login-btn">Login</a>
  </nav>
</header>
<style>
  .site-header { transition: transform 0.3s ease, box-shadow 0.3s ease; will-change: transform; }
  .site-header.nav-header-hidden { transform: translateY(-110%) !important; }
  @media (prefers-reduced-motion: reduce) {
    .site-header { transition: none; }
  }
</style>
<script>
(function () {
  var header = document.querySelector('.site-header') || document;
  header.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (href === '#' || href.indexOf('javascript:') === 0) return;
    if (a.target === '_blank') return;
    e.preventDefault();
    e.stopPropagation();
    window.location.href = href;
  }, true);
  function pageKey() {
    var p = (location.pathname.split('/').pop() || '').toLowerCase();
    if (p === 'announcements.php' || p === 'geo-map-landing.php') return 'updates';
    if (p === 'about.php' || p === 'faq.php' || p === 'contact.php') return 'about';
    if (p === 'adoption.php') return 'adoption';
    return 'home';
  }
  function sync() {
    var k = pageKey();
    var links = document.querySelectorAll('.nav-links a, .nav-links .dropdown-toggle');
    Array.prototype.forEach.call(links, function (a) { a.classList.remove('active'); });
    if (k === 'home') {
      var first = document.querySelector('.nav-links > li:first-child > a');
      if (first) first.classList.add('active');
    } else if (k === 'updates') {
      var upd = document.querySelector('.nav-links > li:nth-child(2) > .dropdown-toggle');
      if (upd) upd.classList.add('active');
    } else if (k === 'about') {
      var ab = document.querySelector('.nav-links > li:nth-child(3) > .dropdown-toggle');
      if (ab) ab.classList.add('active');
    } else if (k === 'adoption') {
      var ad = document.querySelector('.nav-links a[href="adoption.php"]');
      if (ad) ad.classList.add('active');
    }
    var light = (k === 'updates');
    document.body.setAttribute('data-theme-nav', light ? 'light' : 'dark');
    var main = document.querySelector('main.page_main');
    if (main) {
      if (light) { main.classList.add('is-light'); } else { main.classList.remove('is-light'); }
    }
  }
  document.addEventListener('DOMContentLoaded', sync);
  window.addEventListener('popstate', sync);
  var navToggle = document.querySelector('.nav-toggle');
  var navLinks = document.querySelector('.nav-links');
  var navBar = document.querySelector('.site-header .navbar') || document.querySelector('.navbar');
  function bindNav() {
    if (navBar) {
      function sc() { if (navBar) navBar.classList.toggle('nav-scrolled', window.scrollY > 40); }
      window.addEventListener('scroll', sc, { passive: true });
      sc();
    }
    /* Hide navbar on scroll down, reveal on scroll up */
    var st = document.querySelector('.site-header');
    if (st) {
      var lastY = window.scrollY;
      var ticking = false;
      function onScroll() {
        ticking = false;
        var y = window.scrollY;
        var menuOpen = navLinks ? navLinks.classList.contains('open') : false;
        if (menuOpen || y < 120) {
          st.classList.remove('nav-header-hidden');
        } else if (y > lastY) {
          st.classList.add('nav-header-hidden');
        } else {
          st.classList.remove('nav-header-hidden');
        }
        lastY = y;
      }
      window.addEventListener('scroll', function () {
        if (!ticking) {
          ticking = true;
          window.requestAnimationFrame(onScroll);
        }
      }, { passive: true });
    }
    if (navToggle && navLinks) {
      navToggle.addEventListener('click', function (e) {
        e.stopPropagation();
        navLinks.classList.toggle('open');
      });
      document.addEventListener('click', function (e) {
        if (!e.target.closest('.navbar')) navLinks.classList.remove('open');
      });
    }
    document.addEventListener('click', function (e) {
      if (e.target.closest && !e.target.closest('.nav-item')) {
        document.querySelectorAll('.nav-item.open').forEach(function (i) { i.classList.remove('open'); });
      }
    });
    document.querySelectorAll('.dropdown-toggle').forEach(function (tb) {
      tb.addEventListener('click', function (e) {
        var li = this.closest('.nav-item');
        if (li && window.innerWidth < 1100) { e.preventDefault(); li.classList.toggle('open'); }
      });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindNav);
  } else {
    bindNav();
  }
})();
</script>
<script>
(function () {
  var realTitle = document.title || '';
  function setTitleElem(v) {
    try {
      var t = document.getElementsByTagName('title')[0];
      if (t) { t.textContent = v; return; }
    } catch (e) {}
    realTitle = v;
  }
  function ignore() {
    var t = document.title || '';
    if (t.indexOf('On hold') === 0) { setTitleElem(realTitle); }
  }
  try {
    Object.defineProperty(document, 'title', {
      configurable: true,
      enumerable: true,
      get: function () {
        var t = document.getElementsByTagName('title')[0];
        return t ? (t.textContent || '') : realTitle;
      },
      set: function (v) {
        v = String(v || '');
        if (v.indexOf('On hold') === 0) return;
        setTitleElem(v);
      }
    });
  } catch (e) {}
  document.addEventListener('visibilitychange', ignore);
  setInterval(ignore, 300);
})();
</script>