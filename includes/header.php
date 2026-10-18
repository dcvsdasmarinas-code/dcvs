<?php
require_once __DIR__ . '/app-base.php';
$appBaseUrl = dcvsAppBaseUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title><?php echo isset($pageTitle) ? $pageTitle . ' — DCVS' : 'Dasmariñas City Veterinary Services'; ?></title>
<link rel="icon" href="<?php echo htmlspecialchars($appBaseUrl . '/favicon.ico?v=' . (int)filemtime(__DIR__ . '/../favicon.ico'), ENT_QUOTES, 'UTF-8'); ?>" sizes="16x16 32x32 48x48" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
<style>
@font-face{font-family:Khteka;src:url(https://cdn.prod.website-files.com/68b652bbd6c64a44c8fe3e5e/6968b3919a24a99e002f91dc_KHTeka-Medium.woff2)format("woff2");font-weight:500;font-style:normal;font-display:swap}
:root {
  --bg-ice: #e9f3ff;
  --bg-cloud: #f3f8ff;
  --primary: #045E1FD9;
  --primary-dark: #045E1FD9;
  --ink: #045E1FD9;
  --muted: #4a5672;
  --line-soft: rgba(4, 94, 31, 0.85);
  --glass: rgba(233, 243, 255, 0.62);
  --white: #ffffff;
  --accent-blue: #045E1FD9;
  --accent-sand: #e8a80a;
  --shadow-soft: 0 20px 45px rgba(4, 94, 31, 0.85);
  --shadow-card: 0 18px 40px rgba(4, 94, 31, 0.85);
  --transition: cubic-bezier(0.25, 0.65, 0.35, 1);
}
*{box-sizing:border-box;}
body{
  margin:0;
  font-family:'Inter','Segoe UI',sans-serif;
  color:#18181b;
  background:#ffffff;
  min-height:100vh;
  line-height:1.6;
}
img,svg{display:block;max-width:100%;}
a{text-decoration:none;color:inherit;}
.container{width:min(1200px,90vw);margin:0 auto;}
.site-header{
  position:fixed;top:0;left:0;right:0;z-index:1000;padding:0.8rem 0;
}
.navbar{
  display:flex;align-items:center;gap:2rem;
  font-family:'Inter','Segoe UI',sans-serif!important;
  width:min(1200px,92vw);margin:0 auto;padding:0.85rem 1.5rem;
  border-radius:999px;
  background:rgba(255,255,255,0.82);
  -webkit-backdrop-filter:blur(24px) saturate(1.8);
  backdrop-filter:blur(24px) saturate(1.8);
  border:1px solid rgba(255,255,255,0.65);
  box-shadow:0 24px 48px rgba(0,0,0,0.12);
  position:relative;
  transition:background 0.3s var(--transition),box-shadow 0.3s var(--transition),border 0.3s var(--transition),transform 0.3s var(--transition);
}
.brand-logo{display:block;object-fit:contain;}
.navbar::before,.navbar::after{display:none;}
.navbar.nav-scrolled{
  background:rgba(255,255,255,0.88);
  border-color:rgba(0,0,0,0.12);
  box-shadow:0 26px 52px rgba(0,0,0,0.1);
}
.nav-links{display:flex;align-items:center;justify-content:center;gap:1.5rem;list-style:none;margin:0;padding:0;flex:1;}
.nav-links a{
  font-weight:500;padding:6px 16px;border-radius:8px;
  color:#1a2a3a;transition:color 0.25s var(--transition),background 0.25s var(--transition);
}
.nav-links a::after{display:none;}
.nav-links a:hover,
.nav-links a:focus-visible{background:rgba(4,94,31,0.06);color:#045e1f;}
.nav-links a.active{background:rgba(4,94,31,0.1);color:#045e1f;}
.nav-item{position:relative;}
.dropdown-toggle{cursor:pointer;display:flex;align-items:center;gap:4px;padding:6px 16px;border-radius:8px;color:#1a2a3a;transition:background 0.2s,color 0.2s;}
.dropdown-toggle:hover{background:rgba(4,94,31,0.06);color:#045e1f;}
.dropdown-toggle .arrow{font-size:10px;transition:transform 0.3s var(--transition);}
.nav-item.open .dropdown-toggle .arrow{transform:rotate(180deg);}
.dropdown-menu{
  position:absolute;top:calc(100% + 8px);left:0;
  background:#ffffff;border-radius:12px;padding:6px 0;min-width:190px;
  box-shadow:0 20px 48px rgba(0,0,0,0.12);
  opacity:0;visibility:hidden;transform:translateY(8px);
  transition:all 0.25s ease;z-index:9999;
  border:1px solid rgba(0,0,0,0.06);
}
.nav-item:hover .dropdown-menu,
.nav-item.open .dropdown-menu{opacity:1;visibility:visible;transform:translateY(0);}
.dropdown-menu a{
  display:block;padding:10px 20px;font-size:14px;font-weight:500;color:#1a2a3a;white-space:nowrap;
}
.dropdown-menu a::after{display:none;}
.dropdown-menu a:hover{background:rgba(4,94,31,0.06);color:#045e1f;}
.nav-toggle{
  display:none;flex-direction:column;gap:5px;
  background:transparent;border:none;cursor:pointer;padding:0;
}
.nav-toggle span{width:22px;height:2px;background:#1a2a3a;border-radius:999px;transition:transform 0.3s var(--transition),opacity 0.3s var(--transition);}
.nav-login-btn{font-weight:600;font-size:0.88rem;padding:8px 22px;border-radius:8px;background:#045e1f;color:#fff!important;white-space:nowrap;transition:background 0.2s;flex-shrink:0;}
@media(max-width:1100px){
  .navbar{border-radius:24px;}
  .nav-links{
    position:fixed;inset:90px 1.5rem auto 1.5rem;
    background:#ffffff;border-radius:24px;padding:1.8rem;
    flex-direction:column;align-items:flex-start;gap:1rem;
    box-shadow:0 24px 60px rgba(18,39,84,0.25);
    transform:translateY(-20px);opacity:0;pointer-events:none;
    transition:opacity 0.3s var(--transition),transform 0.3s var(--transition);
    max-height:calc(100vh - 110px);overflow-y:auto;
  }
  .nav-links.open{transform:translateY(0);opacity:1;pointer-events:auto;}
  .nav-toggle{display:flex;}
  .nav-login-btn{display:inline-block;}
}
@media(max-width:600px){
  .container{width:95vw;}
}
h1, h2, .about-hero h1, .about-page h2, .faq-hero h1, .contact-hero h1, .section-title, .hero-title, .page-title { font-family:'Inter','Segoe UI',sans-serif !important; font-size:clamp(2rem,1.75rem + 1.25vw,2.5rem) !important; line-height:1 !important; font-weight:600 !important; letter-spacing:-0.025em !important; color:#18181b !important; }
</style>
<link rel="stylesheet" href="assets/css/responsive.css">
<link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/site-navbar.css?v=' . (int)filemtime(__DIR__ . '/../assets/css/site-navbar.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<header class="site-header">
  <nav class="navbar">
    <a href="landing-page.php" class="navbar-brand"><img src="uploads/site/DCVS LOGO.png" alt="DCVS Logo" class="brand-logo" style="height:50px;width:auto;"><span class="navbar-brand-name">Dasmariñas City Veterinary Services</span></a>
    <button class="nav-toggle" aria-label="Toggle navigation"><span></span><span></span><span></span></button>
    <ul class="nav-links">
      <li><a href="landing-page.php" class="<?php echo $activePage === 'home' ? 'active' : ''; ?>">Home</a></li>
      <li class="nav-item">
        <a href="javascript:void(0)" class="dropdown-toggle">Updates <span class="arrow">▾</span></a>
        <div class="dropdown-menu">
          <a href="announcements.php">Public Service Updates</a>
          <a href="geo-map-landing.php">Geo-Map</a>
        </div>
      </li>
      <li class="nav-item">
        <a href="about.php" class="dropdown-toggle <?php echo in_array($activePage, ['about','faq','contact']) ? 'active' : ''; ?>">About <span class="arrow">▾</span></a>
        <div class="dropdown-menu">
          <a href="about.php">About Us</a>
          <a href="faq.php">FAQ</a>
          <a href="contact.php">Contact Us</a>
        </div>
      </li>
      <li><a href="adoption.php">Adoption</a></li>
    </ul>
    <a href="contact.php" class="nav-contact-btn">Contact Us</a>
    <a href="login.php" class="nav-login-btn">Login</a>
  </nav>
</header>
<main>
<script>
document.addEventListener('DOMContentLoaded', function(){
  var toggles = document.querySelectorAll('.dropdown-toggle');
  toggles.forEach(function(t){
    t.addEventListener('click', function(e){
      var li = this.closest('.nav-item');
      if(li && window.innerWidth < 1100){
        e.preventDefault();
        li.classList.toggle('open');
      }
    });
  });
  document.addEventListener('click', function(e){
    if(!e.target.closest('.nav-item')){
      document.querySelectorAll('.nav-item.open').forEach(function(i){ i.classList.remove('open'); });
    }
  });
  // Hamburger menu toggle
  var navToggle = document.querySelector('.nav-toggle');
  var navLinks = document.querySelector('.nav-links');
  if (navToggle && navLinks) {
    navToggle.addEventListener('click', function() {
      navLinks.classList.toggle('open');
    });
    document.addEventListener('click', function(e) {
      if (!e.target.closest('.navbar')) {
        navLinks.classList.remove('open');
      }
    });
  }
});
</script>
<script src="<?php echo htmlspecialchars($appBaseUrl . '/assets/js/navbar-scroll-hide.js?v=' . (int)filemtime(__DIR__ . '/../assets/js/navbar-scroll-hide.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
