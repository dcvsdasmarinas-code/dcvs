<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
    session_set_cookie_params([
        'lifetime' => 30 * 24 * 60 * 60,
        'path'     => '/',
        'domain'   => $_SERVER['HTTP_HOST'] ?? '',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
$apptUrl = (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) ? 'request-appointment.php' : 'register.php';
?><!DOCTYPE html><!-- Last Published: Tue Aug 04 2026 04:01:14 GMT+0000 (Coordinated Universal Time) --><html data-wf-domain="bymonolog.com" data-wf-page="68b652bbd6c64a44c8fe3e53" data-wf-site="68b652bbd6c64a44c8fe3e5e" lang="en"><head><meta charset="utf-8"/><link href="https://cdn.prod.website-files.com" rel="preconnect" crossorigin="anonymous"/><title>Dasmariñas City Veterinary Services | We Connect Communities</title><meta content="We building change-making branding and websites for established creative brands who refuse to be underestimated. Trusted by OH Architecture, Vinamilk, and many other leading brands." name="description"/><meta content="We building change-making branding and websites for established creative brands who refuse to be underestimated. Trusted by OH Architecture, Vinamilk, and many other leading brands." property="og:description"/><meta content="https://cdn.prod.website-files.com/68b652bbd6c64a44c8fe3e5e/6a4df8b2aef24dbe74b85600_OG.jpg" property="og:image"/><meta content="We building change-making branding and websites for established creative brands who refuse to be underestimated. Trusted by OH Architecture, Vinamilk, and many other leading brands." name="twitter:description"/><meta content="https://cdn.prod.website-files.com/68b652bbd6c64a44c8fe3e5e/6a4df8b2aef24dbe74b85600_OG.jpg" name="twitter:image"/><meta property="og:type" content="website"/><meta content="summary_large_image" name="twitter:card"/><meta content="width=device-width, initial-scale=1" name="viewport"/><link href="https://cdn.prod.website-files.com/68b652bbd6c64a44c8fe3e5e/css/bym0n0l0g.webflow.shared.e9a9b6790.min.css" rel="stylesheet" type="text/css" integrity="sha384-6am2eQ02Uw2J+mjX+sKd9mVOysbVCqZFkaXNYM4zRnptppiwgN6myyKrhcq7ME79" crossorigin="anonymous"/><script type="text/javascript">!function(o,c){var n=c.documentElement,t=" w-mod-";n.className+=t+"js",("ontouchstart"in o||o.DocumentTouch&&c instanceof DocumentTouch)&&(n.className+=t+"touch")}(window,document);</script><script>(function(w,i,g){w[g]=w[g]||[];if(typeof w[g].push=='function')w[g].push.apply(w[g],Array.isArray(i)?i:[i]);})(window,['G-NZ1SEFWY0E'],'google_tags_first_party');</script><script async="" src="/v2645sy3qzdjNjhiNjUyYmJkNmM2NGE0NGM4ZmUzZTVl/OZ3ljJ_w_BAmsBzWVeD8lfyivfQ"></script><script type="text/javascript">window.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}gtag('set', 'developer_id.dZGVlNj', true);gtag('set', 'developer_id.dYWYxNW', true);gtag('js', new Date());gtag('config', 'G-NZ1SEFWY0E');</script><style>
  html.lenis,
  html.lenis body {
    height: auto;
  }

  .lenis.lenis-smooth {
    scroll-behavior: auto !important;
  }

  .lenis.lenis-smooth [data-lenis-prevent] {
    overscroll-behavior: contain;
  }

  .lenis.lenis-stopped {
    overflow: hidden;
  }

  .lenis.lenis-smooth iframe {
    pointer-events: none;
  }

  /* Utility Classes */
  .overflow-hidden {
    overflow: hidden;
  }

  .lines-split {
    padding-bottom: 0.025em;
    margin-bottom: -0.025em;
  }

  .hero_home_fade {
    opacity: 1;
  }

  .gap_home_cover {
    width: 21rem !important;
    max-width: 21rem !important;
  }

  [data-animate],
  .navbar_wrap,
  [data-split='heading'],
  .g_eyebrow {
    visibility: hidden;
    opacity: 0;
  }

  .split-line:not(:has(*)),
  .line:not(:has(.word)),
  .split-line:empty {
    height: 1lh; /* or 1em */
  }

  [data-highlight-text] > div:not(:has(div)) {
    height: 1lh;
  }

  /*
.transition_screen, .preloader_wrap {
 display: block;  
}
  */
</style><style>
:root { --primary: #045E1FD9; --ink: #045E1FD9; --muted: #4a5672; --transition: cubic-bezier(0.25,0.65,0.35,1); }
.site-header { position:fixed; top:0; left:0; right:0; z-index:2147483000; padding:0.8rem 0; transform:none!important; }
.navbar { display:flex; align-items:center; gap:2rem; font-family:'Inter','Segoe UI',sans-serif!important; width:min(1200px,92vw); margin:0 auto; padding:0.85rem 1.5rem; border-radius:999px; background:rgba(255,255,255,0.82)!important; -webkit-backdrop-filter:blur(24px) saturate(1.8)!important; backdrop-filter:blur(24px) saturate(1.8)!important; border:1px solid rgba(255,255,255,0.65); box-shadow:0 24px 48px rgba(0,0,0,0.12); position:relative; transition:background 0.3s var(--transition),box-shadow 0.3s var(--transition),border 0.3s var(--transition),transform 0.3s var(--transition); }
.brand-logo { display:block; object-fit:contain; }
.navbar::before, .navbar::after { display:none; }
.navbar.nav-scrolled { background:rgba(255,255,255,0.88); border-color:rgba(0,0,0,0.12); box-shadow:0 26px 52px rgba(0,0,0,0.1); }
.nav-links { display:flex; align-items:center; justify-content:center; gap:1.5rem; list-style:none; margin:0; padding:0; flex:1; }
.nav-links a { font-family:'Inter','Segoe UI',sans-serif!important; font-weight:500!important; font-size:16px!important; letter-spacing:normal!important; line-height:1.5!important; text-decoration:none!important; border-bottom:none!important; text-transform:none!important; padding:6px 16px; border-radius:8px; color:#1a2a3a!important; transition:color 0.25s var(--transition),background 0.25s var(--transition); }
.nav-links a::after { display:none!important; content:none!important; }
.nav-links a:hover, .nav-links a:focus-visible { background:rgba(4,94,31,0.06); color:#045e1f!important; }
.nav-links a.active { background:rgba(4,94,31,0.1); color:#045e1f!important; }
.nav-item { position:relative; margin:0!important; }
.dropdown-toggle { cursor:pointer; display:flex; align-items:center; gap:4px; padding:6px 16px; border-radius:8px; color:#1a2a3a; background:none!important; border:none!important; font-family:'Inter','Segoe UI',sans-serif!important; font-weight:500!important; font-size:16px!important; letter-spacing:normal!important; text-decoration:none!important; transition:background 0.2s,color 0.2s; }
.dropdown-toggle:hover { background:rgba(4,94,31,0.06); color:#045e1f; }
.dropdown-toggle.active { background:rgba(4,94,31,0.1)!important; color:#045e1f!important; }
.dropdown-toggle .arrow { font-size:10px; transition:transform 0.3s var(--transition); color:inherit!important; }
.nav-item.open .dropdown-toggle .arrow { transform:rotate(180deg); }
.dropdown-menu { position:absolute; top:calc(100% + 8px); left:0; background:#ffffff; border-radius:12px; padding:6px 0; min-width:190px; box-shadow:0 20px 48px rgba(0,0,0,0.12); opacity:0; visibility:hidden; transform:translateY(8px); transition:all 0.25s ease; z-index:2147483000; border:1px solid rgba(0,0,0,0.06); }
.nav-item:hover .dropdown-menu, .nav-item.open .dropdown-menu { opacity:1; visibility:visible; transform:translateY(0); }
.dropdown-menu a { display:block; padding:10px 20px; font-size:14px!important; font-family:'Inter','Segoe UI',sans-serif!important; font-weight:500!important; letter-spacing:normal!important; text-decoration:none!important; color:#1a2a3a; white-space:nowrap; }
.dropdown-menu a::after { display:none!important; content:none!important; }
.dropdown-menu a:hover { background:rgba(4,94,31,0.06); color:#045e1f; }
.nav-toggle { display:none; flex-direction:column; gap:5px; background:transparent; border:none; cursor:pointer; padding:0; }
.nav-toggle span { width:22px; height:2px; background:#1a2a3a; border-radius:999px; transition:transform 0.3s var(--transition),opacity 0.3s var(--transition); }
.nav-login-btn { font-family:'Inter','Segoe UI',sans-serif!important; font-weight:600!important; font-size:0.88rem!important; letter-spacing:normal!important; padding:8px 22px; border-radius:8px; background:#045e1f!important; color:#fff!important; white-space:nowrap; transition:background 0.2s; flex-shrink:0; text-decoration:none!important; }
.nav-login-btn:hover { background:#043012!important; }
.nav-login-btn::after { display:none!important; content:none!important; }
@media (max-width:1100px) { .navbar { display:flex; align-items:center; gap:2rem; font-family:'Inter','Segoe UI',sans-serif!important; width:min(1200px,92vw); margin:0 auto; padding:0.85rem 1.5rem; border-radius:24px; background:rgba(255,255,255,0.82)!important; -webkit-backdrop-filter:blur(24px) saturate(1.8)!important; backdrop-filter:blur(24px) saturate(1.8)!important; border:1px solid rgba(255,255,255,0.65); box-shadow:0 24px 48px rgba(0,0,0,0.12); position:relative; transition:background 0.3s var(--transition),box-shadow 0.3s var(--transition),border 0.3s var(--transition),transform 0.3s var(--transition); } .nav-links { position:fixed; inset:90px 1.5rem auto 1.5rem; background:#ffffff; border-radius:24px; padding:1.8rem; flex-direction:column; align-items:flex-start; gap:1rem; box-shadow:0 24px 60px rgba(18,39,84,0.25); transform:translateY(-20px); opacity:0; pointer-events:none; transition:opacity 0.3s var(--transition),transform 0.3s var(--transition); max-height:calc(100vh - 110px); overflow-y:auto; } .nav-links.open { transform:translateY(0); opacity:1; pointer-events:auto; } .nav-toggle { display:flex; } .nav-login-btn { display:inline-block; } }
    .transition_screen { display:none!important; }
    body { overflow:auto!important; }
    .page_wrap { visibility:visible!important; }
    .page_main { visibility:visible!important; }
    .gap_home_p { max-width:56rem; width:100%; text-align:center; }
    .gap_home_bottom { padding-top:4rem; grid-row-gap:1.5rem; }
</style></style>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
<style>
/* Mobile navbar: match About Us (shared navbar responsive rules) */
.nav-links { justify-content: center !important; }
@media (max-width: 1100px) {
  .nav-item .dropdown-menu { display:none !important; position:static !important; box-shadow:none !important; border:none !important; padding-left:1rem !important; opacity:1 !important; visibility:visible !important; transform:none !important; width:100% !important; min-width:auto !important; }
  .nav-item.open .dropdown-menu { display:block !important; }
  .dropdown-menu a { white-space:normal !important; }
  .navbar { padding:0.5rem 1rem !important; gap:0.5rem !important; border-radius:16px !important; }
  .nav-links { gap:0.5rem !important; padding:1rem !important; }
  .brand-logo { height:36px !important; width:auto !important; }
  .nav-login-btn { font-size:0.78rem !important; padding:6px 14px !important; background:#045e1f !important; color:#fff !important; }
  .nav-toggle { display:flex !important; }
  .nav-links a { padding:6px 12px !important; font-size:0.85rem !important; }
  .dropdown-toggle { padding:6px 12px !important; font-size:0.85rem !important; }
}
@media (max-width: 768px) {
  .site-header { padding:0.4rem 0 !important; }
  .navbar { padding:0.4rem 0.85rem !important; gap:0.4rem !important; border-radius:14px !important; }
  .brand-logo { height:32px !important; }
  .nav-links { inset:70px 1rem auto 1rem !important; padding:0.85rem !important; gap:0.4rem !important; }
  .nav-links a { padding:5px 10px !important; font-size:0.8rem !important; }
  .dropdown-toggle { padding:5px 10px !important; font-size:0.8rem !important; }
}
</style>
</head><body data-barba="wrapper" data-navigation-status="is-close" data-about-status="is-closed" data-theme-nav="dark" data-scroll-time="0" class="body">
<?php $currentPage='home'; $homePage='landing-page.php'; include __DIR__ . '/../../includes/landing-navbar.php'; ?>
<div class="transition_screen"></div><div data-cursor="" class="cursor_wrap"><div class="cursor-bubble"><span data-cursor-text-target="" class="cursor-bubble__text u-text-style-main">Hello world</span></div><div class="cursor_css w-embed"><style>
  @media (hover: hover) and (pointer: fine) {


  /* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Bubble base ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
  [data-cursor] .cursor-bubble {
    clip-path: inset(0% 100% 0% 0% round 6px);
    opacity: 0;
    transform: translateX(0%) scale(0.92) rotate(0.001deg);
    transition:
      clip-path 0.45s cubic-bezier(0.16, 1, 0.3, 1),
      opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1),
      transform 0.45s cubic-bezier(0.16, 1, 0.3, 1);
  }

  /* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Active: wipe open left Ã¢â€ â€™ right ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
  [data-cursor='active'] .cursor-bubble {
    clip-path: inset(0% 0% 0% 0% round 6px);
    opacity: 1;
    transform: translateX(0%) scale(1) rotate(0.001deg);
  }

  /* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Active edge: flip to left side ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
  [data-cursor='active-edge'] .cursor-bubble {
    clip-path: inset(0% 0% 0% 0% round 6px);
    opacity: 1;
    transform: translateX(-100%) scale(1) rotate(0.001deg);
  }

  /* ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ Content inside bubble ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ÃƒÂ¢Ã¢â‚¬ÂÃ¢â€šÂ¬ */
  [data-cursor] .cursor-bubble [data-cursor-text-target] {
    opacity: 0;
    transform: translateY(4px);
    transition:
      opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1),
      transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
  }

  [data-cursor='active'] .cursor-bubble [data-cursor-text-target],
  [data-cursor='active-edge'] .cursor-bubble [data-cursor-text-target] {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (hover: none) and (pointer: coarse) {
  .cursor_wrap {
    display: none;
  }
}
</style></div></div><div class="about_wrap"><div class="code-embed-2 w-embed"><style>

.about_overlay_close, .menu_overlay_close {
	opacity: 0;
  visiblity: hidden;
}

.about_modal_contain {
  -webkit-overflow-scrolling: touch;
}

/* .about_modal_wrap {
  transform: translateX(100%)
} */

</style></div><button data-close-modal="" id="" class="about_overlay_close"><span class="about_overlay_text u-sr-only">Close about modal</span></button><div class="about_modal_wrap"><button id="" data-close-modal="" data-btn-default="" class="g_btn_wrapper"><span data-btn-default="" id="" class="g_btn_main is-close"><div class="g_btn_text_contain"><div class="g_btn_text u-text-style-small u-text-trim-off is-about">Close</div></div><div class="g_btn_aside_wrap"><div class="g_btn_aside_bg"></div><span class="g_btn_svg u-text-mono">esc</span><span class="g_btn_svg is-absolute u-text-mono">esc</span></div></span></button><div class="about_modal_contain"><div class="about_modal_top"><div class="g_eyebrow_about"><div class="g_eyebrow_circle"></div><div id="w-node-_269d15e3-bea6-e335-54d1-1b62770afd60-a51e8985" class="g_eyebrow_text u-text-style-large is-about">About the studio</div></div></div><div class="about_modal_content"><p data-split-reveal="lines" data-split="heading" class="about_modal_p u-text-style-h5">We are Dasmariñas City Veterinary Services, dedicated to making access to animal welfare services simple, transparent, and available to everyone.<br/><br/>From reporting and vaccination drives to rabies alerts and public service updates, we bring residents, barangays, and the City Veterinary Office together through one connected platform.<br/><br/>Every case is tracked from report to resolution, ensuring the people and animals of Dasmariñas always get the care they deserve.</p><div class="about_modal_cover"><div class="about_modal_deco_braille"><div class="about_modal_deco_braille"><svg xmlns="http://www.w3.org/2000/svg" width="100%" viewBox="0 0 29 11" fill="none" class="about_modal_braille"><path d="M14 1.5C14 2.32843 13.3284 3 12.5 3C11.6716 3 11 2.32843 11 1.5C11 0.671573 11.6716 0 12.5 0C13.3284 0 14 0.671573 14 1.5Z" fill="currentColor"></path><path d="M25 1.5C25 2.32843 24.3284 3 23.5 3C22.6716 3 22 2.32843 22 1.5C22 0.671573 22.6716 0 23.5 0C24.3284 0 25 0.671573 25 1.5Z" fill="currentColor"></path><path d="M7 1.5C7 2.32843 6.32843 3 5.5 3C4.67157 3 4 2.32843 4 1.5C4 0.671573 4.67157 0 5.5 0C6.32843 0 7 0.671573 7 1.5Z" fill="currentColor"></path><path d="M18 1.5C18 2.32843 17.3284 3 16.5 3C15.6716 3 15 2.32843 15 1.5C15 0.671573 15.6716 0 16.5 0C17.3284 0 18 0.671573 18 1.5Z" fill="currentColor"></path><path d="M18 5.5C18 6.32843 17.3284 7 16.5 7C15.6716 7 15 6.32843 15 5.5C15 4.67157 15.6716 4 16.5 4C17.3284 4 18 4.67157 18 5.5Z" fill="currentColor"></path><path d="M29 5.5C29 6.32843 28.3284 7 27.5 7C26.6716 7 26 6.32843 26 5.5C26 4.67157 26.6716 4 27.5 4C28.3284 4 29 4.67157 29 5.5Z" fill="currentColor"></path><path d="M25 5.5C25 6.32843 24.3284 7 23.5 7C22.6716 7 22 6.32843 22 5.5C22 4.67157 22.6716 4 23.5 4C24.3284 4 25 4.67157 25 5.5Z" fill="currentColor"></path><path d="M3 9.5C3 10.3284 2.32843 11 1.5 11C0.671573 11 0 10.3284 0 9.5C0 8.67157 0.671573 8 1.5 8C2.32843 8 3 8.67157 3 9.5Z" fill="currentColor"></path><path d="M29 9.5C29 10.3284 28.3284 11 27.5 11C26.6716 11 26 10.3284 26 9.5C26 8.67157 26.6716 8 27.5 8C28.3284 8 29 8.67157 29 9.5Z" fill="currentColor"></path></svg></div><div class="about_modal_deco_text u-text-mono">EST 2022</div><div class="about_modal_deco_text u-text-mono">BASEDÃƒâ€šÃ‚Â INÃƒâ€šÃ‚Â MELBOURNE and HANOI</div></div><div class="about_modal_video_wrap"><video src="https://byhuy.b-cdn.net/WebM/Looping%20About%20photo.webm" loop="" muted="" playsinline=" " STYLE="" autoplay="" class="about_modal_video u-background-skeleton about-modal-video"></video><div class="about_modal_overlay"></div><div class="about_modal_text u-text-style-h2">listen</div><div class="about_modal_text u-text-style-h2 is-1">create</div><div class="about_modal_text u-text-style-h2 is-2">obsess</div><div class="about_modal_text u-text-style-h2 is-3">inspire</div></div></div></div><div class="about_modal_clients"><div class="about_modal_circle"><div class="g_eyebrow_circle"></div><h2 class="about_modal_heading u-text-style-h5">Clients</h2></div><div class="about_clients_collection w-dyn-list"><div role="list" class="about_clients_list w-dyn-items"><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Vinamilk</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Moc Chau Creamery</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">OH Architecture</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Supersolid Agency</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Mammoth Murals</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">SLIK Agency</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">BlueBrown Partners</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Backhouse</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">University of Sydney</h2></div></div></div></div><div class="about_modal_awards"><div class="about_modal_circle"><div class="g_eyebrow_circle"></div><h2 class="about_modal_heading u-text-style-h5">Awards</h2></div><div class="about_clients_collection w-dyn-list"><div role="list" class="about_clients_list w-dyn-items"><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Awwwards SOTD (x5)</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">FWA SOTD (x5)</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">CSSDA SOTD (x5)</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Awwwards Honorable Mention (x8)</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Awwwards Typography Honors (x2)</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Awwwards Independent of The Year Nominee (x1)</h2></div></div></div></div><div class="about_modal_values"><div class="about_modal_circle"><div class="g_eyebrow_circle"></div><h2 class="about_modal_heading u-text-style-h5">Our principles</h2></div><div class="about_values_collection w-dyn-list"><div role="list" class="about_values_list w-dyn-items"><div role="listitem" class="about_values_item w-dyn-item"><h2 class="about_values_heading u-text-style-h5">Outcomes first, taste second</h2><p class="about_values_p u-text-style-small">Every creative decision we make is interrogated against one question: does this actually serve your growth?</p></div><div role="listitem" class="about_values_item w-dyn-item"><h2 class="about_values_heading u-text-style-h5">All in or nothing</h2><p class="about_values_p u-text-style-small">We take on fewer projects so we can give each one everything. When we commit to your brand, we&#x27;re fully present, fully invested, fully responsible for the result.</p></div><div role="listitem" class="about_values_item w-dyn-item"><h2 class="about_values_heading u-text-style-h5">Human-first, always</h2><p class="about_values_p u-text-style-small">Behind every brand is a person with a real story and real stakes. We never lose sight of that. The most powerful digital experiences are the ones that feel unmistakably human.</p></div><div role="listitem" class="about_values_item w-dyn-item"><h2 class="about_values_heading u-text-style-h5">Intention over speed</h2><p class="about_values_p u-text-style-small">Rushed work compounds into regret. We move at the pace the work demands. Every layer earns its place before we move to the next.</p></div></div></div></div></div></div></div><div class="menu_wrap"><div class="menu_contain"><ul class="menu_contain_nav u-gap-small u-hflex-left-center"><li data-wf--footer-link--variant="base" class="footer_nav_li"><button id="" data-open-modal="" data-hover-highlight="link" class="footer_nav_text"><div data-hover-heading="" class="footer_nav_span u-text-style-h3">About</div><div data-footer-arrow="" class="footer_nav_span u-text-style-h3 is-arrow"></div></button></li><li data-wf--footer-link--variant="base" class="footer_nav_li"><a href="#work" data-hover-highlight="link" class="footer_nav_text w-inline-block"><div data-hover-heading="" class="footer_nav_span u-text-style-h3">Work</div><div data-footer-arrow="" class="footer_nav_span u-text-style-h3 is-arrow"></div></a></li><li data-wf--footer-link--variant="base" class="footer_nav_li"><a href="#process" data-hover-highlight="link" class="footer_nav_text w-inline-block"><div data-hover-heading="" class="footer_nav_span u-text-style-h3">Process</div><div data-footer-arrow="" class="footer_nav_span u-text-style-h3 is-arrow"></div></a></li><li data-wf--footer-link--variant="base" class="footer_nav_li"><a href="#services" data-hover-highlight="link" class="footer_nav_text w-inline-block"><div data-hover-heading="" class="footer_nav_span u-text-style-h3">Services</div><div data-footer-arrow="" class="footer_nav_span u-text-style-h3 is-arrow"></div></a></li><li data-wf--footer-link--variant="base" class="footer_nav_li"><a data-hover-highlight="link" class="footer_nav_text w-inline-block"><div data-hover-heading="" class="footer_nav_span u-text-style-h3">Contact</div><div data-footer-arrow="" class="footer_nav_span u-text-style-h3 is-arrow"></div></a></li></ul></div><div class="menu_popup_collection w-dyn-list"><div role="list" class="menu_popup_list w-dyn-items"><div role="listitem" class="menu_popup_item w-dyn-item"><a href="#" class="menu_popup_link w-inline-block"><div class="menu_popup_cover"><img loading="lazy" src="https://cdn.prod.website-files.com/68b66e92dfdef050e1802fa7/697db3aecf1ddb096d2ec598_LinkedIn_ProfilePics_2.avif" alt="" sizes="100vw" srcset="https://cdn.prod.website-files.com/68b66e92dfdef050e1802fa7/697db3aecf1ddb096d2ec598_LinkedIn_ProfilePics_2-p-500.avif 500w, https://cdn.prod.website-files.com/68b66e92dfdef050e1802fa7/697db3aecf1ddb096d2ec598_LinkedIn_ProfilePics_2.avif 1000w" class="menu_popup_image"/></div><div class="menu_popup_content"><p class="menu_popup_p u-text-style-small">Supersolid onboards FujiFilm Australia and Hyatt Hotels as clients</p><span class="menu_popup_date u-text-mono">January 20, 2026</span></div></a><svg xmlns="http://www.w3.org/2000/svg" width="100%" viewBox="0 0 12 12" fill="none" class="g_btn_svg"><path d="M8.90954 9.09046L9 3L2.90954 3.09046L2.90213 4.32367L6.86437 4.25391L2.55914 8.55914L3.44086 9.44086L7.74609 5.13563L7.68708 9.10862L8.90954 9.09046Z" fill="currentColor"></path></svg></div></div></div><button data-close-modal="" id="" class="menu_overlay_close"></button></div><div class="g_overlay"><div class="g_grain_overlay u-grain-animate"><div class="w-embed"><style>
.g_grain_overlay {
  will-change: transform;
}

@keyframes grain-animation {
  0%, 100% { 
    transform: translate(0, 0); 
  }
  17% { 
    transform: translate(-5%, -10%); 
  }
  33% { 
    transform: translate(3%, -15%); 
  }
  50% { 
    transform: translate(12%, 9%); 
  }
  67% { 
    transform: translate(9%, 4%); 
  }
  83% { 
    transform: translate(-1%, 7%); 
  }
}

.u-grain-animate {
  animation: grain-animation 0.5s steps(6) infinite;
}
</style></div></div></div><div class="u-position-fixed"><div class="w-embed"><style>
:root {
  --grid-breakout: [full-start] minmax(0, 1fr) [content-start] repeat(var(--site--column-count), minmax(0, var(--site--column-width))) [content-end] minmax(0, 1fr) [full-end];
  --grid-breakout-single: [full-start] minmax(0, 1fr) [content-start] minmax(0, calc(100% - var(--site--margin) * 2)) [content-end] minmax(0, 1fr) [full-end];
  
   --column-width--1: calc((100% - ((var(--site--column-count) - 1) * var(--site--gutter))) / var(--site--column-count));
  --column-width--2: calc(var(--column-width--1) * 2 + (var(--site--gutter) * 1));
  --column-width--3: calc(var(--column-width--1) * 3 + (var(--site--gutter) * 2));
  --column-width--4: calc(var(--column-width--1) * 4 + (var(--site--gutter) * 3));
  --column-width--5: calc(var(--column-width--1) * 5 + (var(--site--gutter) * 4));
  --column-width--6: calc(var(--column-width--1) * 6 + (var(--site--gutter) * 5));
  --column-width--7: calc(var(--column-width--1) * 7 + (var(--site--gutter) * 6));
  --column-width--8: calc(var(--column-width--1) * 8 + (var(--site--gutter) * 7));
  --column-width--9: calc(var(--column-width--1) * 9 + (var(--site--gutter) * 8));
  --column-width--10: calc(var(--column-width--1) * 10 + (var(--site--gutter) * 9));
  --column-width--11: calc(var(--column-width--1) * 11 + (var(--site--gutter) * 10));
  --column-width--12: calc(var(--column-width--1) * 12 + (var(--site--gutter) * 11));

  --column-margin--1: calc(var(--column-width--1) + var(--site--gutter));
  --column-margin--2: calc(var(--column-width--2) + var(--site--gutter));
  --column-margin--3: calc(var(--column-width--3) + var(--site--gutter));
  --column-margin--4: calc(var(--column-width--4) + var(--site--gutter));
  --column-margin--5: calc(var(--column-width--5) + var(--site--gutter));
  --column-margin--6: calc(var(--column-width--6) + var(--site--gutter));
  --column-margin--7: calc(var(--column-width--7) + var(--site--gutter));
  --column-margin--8: calc(var(--column-width--8) + var(--site--gutter));
  --column-margin--9: calc(var(--column-width--9) + var(--site--gutter));
  --column-margin--10: calc(var(--column-width--10) + var(--site--gutter));
  --column-margin--11: calc(var(--column-width--11) + var(--site--gutter));
  --column-margin--12: calc(var(--column-width--12) + var(--site--gutter));
  
  --grid-1: repeat(1, minmax(0, 1fr));
	--grid-2: repeat(2, minmax(0, 1fr));
	--grid-3: repeat(3, minmax(0, 1fr));
	--grid-4: repeat(4, minmax(0, 1fr));
	--grid-5: repeat(5, minmax(0, 1fr));
	--grid-6: repeat(6, minmax(0, 1fr));
	--grid-7: repeat(7, minmax(0, 1fr));
	--grid-8: repeat(8, minmax(0, 1fr));
	--grid-9: repeat(9, minmax(0, 1fr));
	--grid-10: repeat(10, minmax(0, 1fr));
	--grid-11: repeat(11, minmax(0, 1fr));
	--grid-12: repeat(12, minmax(0, 1fr));
  
  /* Column Span Variables */
	--span-1: span 1 / span 1;
	--span-2: span 2 / span 2;
	--span-3: span 3 / span 3;
	--span-4: span 4 / span 4;
	--span-5: span 5 / span 5;
	--span-6: span 6 / span 6;
	--span-7: span 7 / span 7;
	--span-8: span 8 / span 8;
	--span-9: span 9 / span 9;
	--span-10: span 10 / span 10;
	--span-11: span 11 / span 11;
	--span-12: span 12 / span 12;

	--animation-primary: cubic-bezier(0.83, 0, 0.17, 1);
  --animation-secondary: cubic-bezier(0.31,0.75,0.22,1);
  --animation-expo-out: cubic-bezier(0.2, 1, 0.36, 1);
  --duration-fast: 0.45s;

  /* Column Start Position Variables */
  --start-1: 1;
  --start-2: 2;
  --start-3: 3;
  --start-4: 4;
  --start-5: 5;
  --start-6: 6;
  --start-7: 7;
  --start-8: 8;
  --start-9: 9;
  --start-10: 10;
  --start-11: 11;
  --start-12: 12;
  
}
::before, ::after {
	box-sizing: border-box;
}
.w-embed:before, .w-embed:after,
.w-richtext:before, .w-richtext:after {
	content: unset;
}
html {
	background-color: var(--_theme---background);
}
button {
	background-color: unset;
	padding: unset;
	text-align: inherit;
}
button:not(:disabled) {
	cursor: pointer;
}
video {
	width: 100%;
	object-fit: cover;
}
/* remove padding of empty element */
.wf-empty {
	padding: 0;
}
svg {
	max-width: 100%;
}
@media (prefers-color-scheme: light) {
	option { color: black; }
}
img::selection {
	background: transparent;
}
/* Typography */
body {
	text-transform: var(--_text-style---text-transform);
	font-smoothing: antialiased;
	-webkit-font-smoothing: antialiased;
}
/* Clear Defaults */
a:not([class]) {
	text-decoration: underline;
}
h1,h2,h3,h4,h5,h6,p,blockquote,label {
	font-family: inherit;
	font-size: inherit;
	font-weight: inherit;
	line-height: inherit;
	letter-spacing: inherit;
	text-transform: inherit;
	text-wrap: inherit;
	margin-top: 0;
	margin-bottom: 0;
}
select:has(option[value=""]:checked) {
	color: color-mix(in lab, currentcolor 60%, transparent)
}
/* Selection Color */
::selection {
	background-color: var(--_theme---selection--background);
	color: var(--_theme---selection--text);
}
/* Margin Trim */
:is(.u-margin-trim, .u-rich-text) > :not(:not(.w-condition-invisible) ~ :not(.w-condition-invisible)),
:is(.u-margin-trim, .u-rich-text) > :not(:not(.w-condition-invisible) ~ :not(.w-condition-invisible)).u-display-contents > :first-child {
	margin-top: 0;
}
:is(.u-margin-trim, .u-rich-text) > :not(:has(~ :not(.w-condition-invisible))),
:is(.u-margin-trim, .u-rich-text) > :not(:has(~ :not(.w-condition-invisible))).u-display-contents > :last-child {
	margin-bottom: 0;
}
/* Line Height Trim */
:is([class*="u-text-style-"],h1,h2,h3,h4,h5,h6,p):not(.w-richtext,.u-text-trim-off)::before {
	content: "";
	display: table;
	margin-bottom: calc(-0.5lh + var(--_text-style---trim-top));
}
:is([class*="u-text-style-"],h1,h2,h3,h4,h5,h6,p):not(.w-richtext,.u-text-trim-off)::after {
	content: "";
	display: table;
	margin-bottom: calc(-0.5lh + var(--_text-style---trim-bottom));
}
/* Rich Text Links */
.w-richtext a {
	position: relative;
	z-index: 4;
}
/* Line Clamp */
.u-line-clamp-1, .u-line-clamp-2, .u-line-clamp-3, .u-line-clamp-4 {
	-webkit-line-clamp: 1;
	-webkit-box-orient: vertical;
}
.u-line-clamp-2 { -webkit-line-clamp: 2; }
.u-line-clamp-3 { -webkit-line-clamp: 3; }
.u-line-clamp-4 { -webkit-line-clamp: 4; }
/* Child Contain */
.u-child-contain > * {
	width: 100%;
	max-width: inherit !important;
  margin-inline: 0 !important;
	margin-top: 0 !important;
}
/* Hide */
.u-hide-if-empty:empty,
.u-hide-if-empty:not(:has(> :not(.w-condition-invisible))),
.u-hide-if-empty-cms:not(:has(.w-dyn-item)),
.u-embed-js,
.u-embed-css {
	display: none !important;
}
/* Focus State */
a, button, :where([tabindex]), [data-outline] {
	outline-offset: var(--focus--offset-outer);
}
a:focus-visible,
button:focus-visible,
[tabindex]:focus-visible,
label:has(input:focus-visible) [data-outline] {
	outline-color: var(--_theme---text);
	outline-width: var(--focus--width);
	outline-style: solid;
}
/* Global / Clickable Component */
.wf-design-mode .clickable_wrap {
	z-index: 0;
}
.clickable_wrap a[href="#"] {
	display: none;
}
.clickable_wrap a[href="#"] ~ button {
	display: block;
}
/* Responsive Above */
@container threshold-large (width >= 62em) {
	.u-order-unset-above { order: unset !important; }
	.u-all-unset-above { all: unset !important; }
	.u-grid-below { display: flex !important; }
}
@container threshold-medium (width >= 48em) {
	.u-order-unset-above { order: unset !important; }
	.u-all-unset-above { all: unset !important; }
	.u-grid-below { display: flex !important; }
}
@container threshold-small (width >= 30em) {
	.u-order-unset-above { order: unset !important; }
	.u-all-unset-above { all: unset !important; }
	.u-grid-below { display: flex !important; }
}
/* Responsive Below */
@container threshold-large (width < 62em) {
	.u-order-unset-below { order: unset !important; }
	.u-all-unset-below { all: unset !important; }
	.u-grid-above { display: flex !important; }
}
@container threshold-medium (width < 48em) {
	.u-order-unset-below { order: unset !important; }
	.u-all-unset-below { all: unset !important; }
	.u-grid-above { display: flex !important; }
}
@container threshold-small (width < 30em) {
	.u-order-unset-below { order: unset !important; }
	.u-all-unset-below { all: unset !important; }
	.u-grid-above { display: flex !important; }
}
/* Form Radio */
.form_main_radio_label:has(input:checked) .form_main_radio_circle_inner {
	opacity: 1;
}
/* Form Checkbox */
.form_main_checkbox_label:has(input:checked) .form_main_checkbox_box {
	background-color: currentColor;
	border-color: currentColor;
}
.form_main_checkbox_label:has(input:checked) .form_main_checkbox_icon {
	opacity: 1;
}
</style></div><div class="page_code_custom w-embed"><style>
  /* Grid Layout */
  [data-column-span='1'] {
    grid-column: var(--span-1);
  }
  [data-column-span='2'] {
    grid-column: var(--span-2);
  }
  [data-column-span='3'] {
    grid-column: var(--span-3);
  }
  [data-column-span='4'] {
    grid-column: var(--span-4);
  }
  [data-column-span='5'] {
    grid-column: var(--span-5);
  }
  [data-column-span='6'] {
    grid-column: var(--span-6);
  }
  [data-column-span='7'] {
    grid-column: var(--span-7);
  }
  [data-column-span='8'] {
    grid-column: var(--span-8);
  }
  [data-column-span='9'] {
    grid-column: var(--span-9);
  }
  [data-column-span='10'] {
    grid-column: var(--span-10);
  }
  [data-column-span='11'] {
    grid-column: var(--span-11);
  }
  [data-column-span='12'] {
    grid-column: var(--span-12);
  }

  [data-column-start='1'] {
    grid-column-start: var(--start-1);
  }
  [data-column-start='2'] {
    grid-column-start: var(--start-2);
  }
  [data-column-start='3'] {
    grid-column-start: var(--start-3);
  }
  [data-column-start='4'] {
    grid-column-start: var(--start-4);
  }
  [data-column-start='5'] {
    grid-column-start: var(--start-5);
  }
  [data-column-start='6'] {
    grid-column-start: var(--start-6);
  }
  [data-column-start='7'] {
    grid-column-start: var(--start-7);
  }
  [data-column-start='8'] {
    grid-column-start: var(--start-8);
  }
  [data-column-start='9'] {
    grid-column-start: var(--start-9);
  }
  [data-column-start='10'] {
    grid-column-start: var(--start-10);
  }
  [data-column-start='11'] {
    grid-column-start: var(--start-11);
  }
  [data-column-start='12'] {
    grid-column-start: var(--start-12);
  }

  /* Link & Button Hover */
  @media (hover: hover) and (pointer: fine) {
    [data-hover-heading],
    .g_btn_svg {
      transition: transform 0.65s cubic-bezier(0.16, 1, 0.35, 1);
    }

    [data-footer-arrow] {
      transition:
        transform 0.65s cubic-bezier(0.16, 1, 0.35, 1),
        opacity 0.3s cubic-bezier(0.16, 1, 0.35, 1),
        filter 0.3s cubic-bezier(0.16, 1, 0.35, 1);
    }

    /* Link underline fill */
    [data-link-hover]::before {
      content: '';
      position: absolute;
      bottom: 0px;
      left: 0;
      z-index: -1;
      width: 100%;
      height: 100%;
      transform-origin: right;
      transform: scaleX(0);
      background-color: var(--swatch--black-300);
      transition: transform 900ms var(--animation-expo-out);
    }

    [data-theme-nav="light"] [data-link-hover]::before {
      background-color: var(--swatch--beige-400);
    }
    

    [data-link-hover]:hover::before {
      transform-origin: left;
      transform: scaleX(1);
    }

    /* Button */
    [data-btn-default] {
      --active-ease: cubic-bezier(0.48, 1.68, 0.64, 1);
      --active-speed: 0.35s;
      transition:
        transform var(--active-speed) var(--active-ease),
        opacity var(--active-speed) cubic-bezier(0.16, 1, 0.35, 1);
    }

    [data-btn-default]:hover {
      opacity: 0.9;
    }

    [data-btn-default]:active {
      transform: scale(0.95);
    }

   

    [data-btn-default]:hover .g_btn_text {
      transform: translateY(-1.2em);
    }

    [data-btn-default]:hover .g_btn_svg {
      transform: translate(100%, -100%);
    }

    [data-btn-default]:hover .g_btn_svg.is-absolute {
      transform: translate(0%);
    }

    [data-btn-default]:hover .g_btn_aside_bg {
      transform: scale(0.8);
    }

      .g_btn_text.is-about {
      text-shadow: 0px 1.2em 0px var(--swatch--beige-100) !important;
    }

    /* Nav transitions */
    .navbar_contain,
    .navbar_home_svg,
    .navbar_left_sound_container,
    .navbar_menu_span {
      transition:
        color 0.25s ease,
        background-color 0.25s ease;
    }

    /* Merged: preserves transform for :active scale + hover opacity */
    .navbar_cta_contain .g_btn_main {
      transition:
        transform var(--active-speed) var(--active-ease),
        opacity var(--active-speed) cubic-bezier(0.16, 1, 0.35, 1),
        color 0.25s ease,
        background-color 0.25s ease;
    }

    /* Merged: preserves transform for scale(0.9) on hover */
    .g_btn_aside_bg {
      transition:
        transform 0.65s cubic-bezier(0.16, 1, 0.35, 1),
        color 0.25s ease,
        background-color 0.25s ease;
    }

    .g_btn_text {
            transition: transform 0.65s cubic-bezier(0.16, 1, 0.35, 1);

    }

    /* Nav Theme ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â Light */
    [data-theme-nav='light'] {
	.g_btn_text {
      text-shadow: 0px 1.2em 0px var(--swatch--beige-100);
    }
    
      
      & .navbar_contain,
      & .navbar_home_svg,
      & .navbar_menu_span {
        color: var(--swatch--black-400);
      }

      & .navbar_left_sound_container {
        background-color: var(--swatch--beige-400);
      }

      & .navbar_cta_contain .g_btn_main {
        background-color: var(--swatch--card-bg);
        color: var(--swatch--beige-100);
      }

      & .navbar_cta_contain .g_btn_aside_bg {
        background-color: var(--swatch--black-200);
      }

      /* Freeze nav colors when menu is open */
      &[data-navigation-status='is-open'] {
        & .navbar_contain,
        & .navbar_home_svg,
        & .navbar_menu_span,
        & .navbar_left_sound_container {
          color: unset;
          background-color: unset;
        }
      }
    }

    /* Nav Theme ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â Dark */
    [data-theme-nav='dark'] {
		 /* Button text marquee */
   	.g_btn_text {
      text-shadow: 0px 1.2em 0px var(--swatch--black-400);
    }
      
      & .navbar_contain,
      & .navbar_home_svg,
      & .navbar_menu_span {
        color: var(--swatch--beige-100);
      }

      & .navbar_left_sound_container {
        background-color: var(--swatch--card-bg);
      }

      & .navbar_cta_contain .g_btn_main {
        background-color: var(--swatch--beige-100);
        color: var(--swatch--black-400);
      }

      & .navbar_cta_contain .g_btn_aside_bg {
        background-color: var(--swatch--black-400);
      }
    }
  }

  /* Highlight row hover ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â desktop only */
  @media (hover: hover) and (pointer: fine) and (min-width: 768px) {
    [data-hover-highlight]:hover [data-hover-heading] {
      transform: translateX(10px);
      color: #080807;
    }

    [data-hover-highlight]:hover .accordion_css_square {
      transform: translateX(-10px);
    }

    [data-hover-highlight]:hover [data-footer-arrow] {
      transform: translateX(-10px);
      filter: blur(0px);
      opacity: 1;
      color: #080807;
    }
  }

  .navbar_menu_span {
    transform: translateY(0%);
  }

  .navbar_menu_span.is-close {
    transform: translateY(100%); /* hidden below initially */
  }

  [data-navigation-status='is-open'] {
    .navbar_menu_span {
      transform: translateY(-100%); /* slide up, hide */
    }

    .navbar_menu_span.is-close {
      transform: translateY(0%); /* slide into view */
    }
  }
</style></div></div><div class="guide_wrap"><div class="w-embed"><style>
html.wf-design-mode .guide_wrap {
	display: block;
}
.guide_layout {
	counter-reset: gridguides;
}
.guide_layout > div::before {
  counter-increment: gridguides;
  content: counter(gridguides);
}
</style></div><div data-padding-bottom="none" data-padding-top="none" class="guide_contain u-container"><div class="guide_layout"><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div></div></div></div><div data-barba-namespace="home" data-barba="container" class="page_wrap"><main class="page_main"><style>
/* ===== DCVS Hero Section (as uploaded - mamm0thmur4ls design tokens, scoped to the hero) ===== */
@font-face{font-family:Suisseintl;src:url(https://cdn.prod.website-files.com/6870db6428fa0046e4e9dc88/68805858e095a766b86eb9f6_SuisseIntl-SemiBold-WebM.woff2)format("woff2");font-weight:600;font-style:normal;font-display:swap}
@font-face{font-family:Suisseintl;src:url(https://cdn.prod.website-files.com/6870db6428fa0046e4e9dc88/6882e67a90d89df1a9887176_SuisseIntl-Medium-WebM.woff2)format("woff2");font-weight:500;font-style:normal;font-display:swap}
@font-face{font-family:SuisseIntl;src:url(https://cdn.prod.website-files.com/6870db6428fa0046e4e9dc88/6882e67a90d89df1a9887176_SuisseIntl-Medium-WebM.woff2)format("woff2");font-weight:500;font-style:normal;font-display:swap}
@font-face{font-family:Obviously;src:url(https://cdn.prod.website-files.com/6870db6428fa0046e4e9dc88/6892c333bf67b1d5eafe58a0_Obviously-CondensedBlack.woff2)format("woff2");font-weight:800;font-style:normal;font-display:swap}
.hero_home_wrap{
  --site--margin: clamp(1rem,.714286rem + 1.42857vw,2rem);
  --site--gutter: 1rem;
  --site--column-count: 12;
  --site--viewport-min: 20;
  --site--viewport-max: 100;
  --max-width--main: calc(var(--site--viewport-max)*1rem);
  --site--column-width: calc((min(var(--max-width--main),100% - var(--site--margin)*2) - (var(--site--gutter)*(var(--site--column-count) - 1)))/var(--site--column-count));
  --column-width--1: calc((100% - ((var(--site--column-count) - 1) * var(--site--gutter))) / var(--site--column-count));
  --column-width--2: calc(var(--column-width--1) * 2 + (var(--site--gutter) * 1));
  --column-width--3: calc(var(--column-width--1) * 3 + (var(--site--gutter) * 2));
  --column-width--4: calc(var(--column-width--1) * 4 + (var(--site--gutter) * 3));
  --column-width--5: calc(var(--column-width--1) * 5 + (var(--site--gutter) * 4));
  --column-width--6: calc(var(--column-width--1) * 6 + (var(--site--gutter) * 5));
  --column-width--7: calc(var(--column-width--1) * 7 + (var(--site--gutter) * 6));
  --column-width--8: calc(var(--column-width--1) * 8 + (var(--site--gutter) * 7));
  --column-width--9: calc(var(--column-width--1) * 9 + (var(--site--gutter) * 8));
  --column-width--10: calc(var(--column-width--1) * 10 + (var(--site--gutter) * 9));
  --column-width--11: calc(var(--column-width--1) * 11 + (var(--site--gutter) * 10));
  --column-width--12: calc(var(--column-width--1) * 12 + (var(--site--gutter) * 11));
  --_spacing---space--1: clamp(.375*1rem,((.375 - ((.5 - .375)/(var(--site--viewport-max) - var(--site--viewport-min))*var(--site--viewport-min)))*1rem + ((.5 - .375)/(var(--site--viewport-max) - var(--site--viewport-min)))*100vw),.5*1rem);
  --_spacing---space--2: clamp(.625*1rem,((.625 - ((.75 - .625)/(var(--site--viewport-max) - var(--site--viewport-min))*var(--site--viewport-min)))*1rem + ((.75 - .625)/(var(--site--viewport-max) - var(--site--viewport-min)))*100vw),.75*1rem);
  --_spacing---space--3: clamp(.875*1rem,((.875 - ((1 - .875)/(var(--site--viewport-max) - var(--site--viewport-min))*var(--site--viewport-min)))*1rem + ((1 - .875)/(var(--site--viewport-max) - var(--site--viewport-min)))*100vw),1*1rem);
  --_spacing---space--4: clamp(1.25*1rem,((1.25 - ((1.5 - 1.25)/(var(--site--viewport-max) - var(--site--viewport-min))*var(--site--viewport-min)))*1rem + ((1.5 - 1.25)/(var(--site--viewport-max) - var(--site--viewport-min)))*100vw),1.5*1rem);
  --_spacing---space--5: clamp(1.75*1rem,((1.75 - ((2 - 1.75)/(var(--site--viewport-max) - var(--site--viewport-min))*var(--site--viewport-min)))*1rem + ((2 - 1.75)/(var(--site--viewport-max) - var(--site--viewport-min)))*100vw),2*1rem);
  --_spacing---space--6: clamp(2*1rem,((2 - ((2.5 - 2)/(var(--site--viewport-max) - var(--site--viewport-min))*var(--site--viewport-min)))*1rem + ((2.5 - 2)/(var(--site--viewport-max) - var(--site--viewport-min)))*100vw),2.5*1rem);
  --_spacing---space--7: clamp(2.25*1rem,((2.25 - ((3 - 2.25)/(var(--site--viewport-max) - var(--site--viewport-min))*var(--site--viewport-min)))*1rem + ((3 - 2.25)/(var(--site--viewport-max) - var(--site--viewport-min)))*100vw),3*1rem);
  --_spacing---space--8: clamp(2.5*1rem,((2.5 - ((4 - 2.5)/(var(--site--viewport-max) - var(--site--viewport-min))*var(--site--viewport-min)))*1rem + ((4 - 2.5)/(var(--site--viewport-max) - var(--site--viewport-min)))*100vw),4*1rem);
  --_spacing---section-space--main: clamp(4*1rem,((4 - ((7 - 4)/(var(--site--viewport-max) - var(--site--viewport-min))*var(--site--viewport-min)))*1rem + ((7 - 4)/(var(--site--viewport-max) - var(--site--viewport-min)))*100vw),7*1rem);
  --_spacing---section-space--large: clamp(5.5*1rem,((5.5 - ((10 - 5.5)/(var(--site--viewport-max) - var(--site--viewport-min))*var(--site--viewport-min)))*1rem + ((10 - 5.5)/(var(--site--viewport-max) - var(--site--viewport-min)))*100vw),10*1rem);
  --_typography---font--primary-family: Suisseintl,Arial,sans-serif;
  --_typography---font--secondary-family: Obviously,Arial,sans-serif;
  --_typography---font--secondary-black: 800;
  --_typography---font--primary-medium: 500;
  --_typography---font--primary-semibold: 600;
  --_typography---font--primary-trim-top: .4em;
  --_typography---font--primary-trim-bottom: .4em;
  --_typography---font-size--display: clamp(3.5*1rem,((3.5 - ((11.25 - 3.5)/(var(--site--viewport-max) - var(--site--viewport-min))*var(--site--viewport-min)))*1rem + ((11.25 - 3.5)/(var(--site--viewport-max) - var(--site--viewport-min)))*100vw),11.25*1rem);
  --_typography---font-size--text-large: clamp(1.125*1rem,((1.125 - ((1.5 - 1.125)/(var(--site--viewport-max) - var(--site--viewport-min))*var(--site--viewport-min)))*1rem + ((1.5 - 1.125)/(var(--site--viewport-max) - var(--site--viewport-min)))*100vw),1.5*1rem);
  --_typography---font-size--text-small: clamp(.875*1rem,((.875 - ((1 - .875)/(var(--site--viewport-max) - var(--site--viewport-min))*var(--site--viewport-min)))*1rem + ((1 - .875)/(var(--site--viewport-max) - var(--site--viewport-min)))*100vw),1*1rem);
  --_typography---font-size--text-xsmall: clamp(.75*1rem,((.75 - ((.875 - .75)/(var(--site--viewport-max) - var(--site--viewport-min))*var(--site--viewport-min)))*1rem + ((.875 - .75)/(var(--site--viewport-max) - var(--site--viewport-min)))*100vw),.875*1rem);
  --_typography---font-size--text-main: clamp(1*1rem,((1 - ((1.125 - 1)/(var(--site--viewport-max) - var(--site--viewport-min))*var(--site--viewport-min)))*1rem + ((1.125 - 1)/(var(--site--viewport-max) - var(--site--viewport-min)))*100vw),1.125*1rem);
  --_typography---line-height--0-85: .85;
  --_typography---line-height--1-3: 1.3;
  --_typography---letter-spacing--0-005em: -.005em;
  --_typography---letter-spacing--0-03em: -.03em;
  --_typography---text-transform--none: var(--text-transform,none);
  --swatch--yellow: #ffd900;
  --swatch--light: #fff6e5;
  --swatch--black: #120011;
  --radius--x-small: .25rem;
  --animation-primary: cubic-bezier(0.83, 0, 0.17, 1);
  --animation-secondary: cubic-bezier(0.31, 0.75, 0.22, 1);
  --duration-fast: 0.45s;
  background-color:#ffffff !important;
  min-height:100svh;
  height:auto;
  padding-top:0;
  padding-right:var(--site--margin);
  padding-bottom:var(--_spacing---space--3);
  padding-left:var(--site--margin);
  font-family:var(--_typography---font--primary-family);
  color:#000000;
  display:block;
  position:relative;
}
.hero_home_wrap h1,
.hero_home_wrap p{
  margin:0;
  font-size:inherit;
  font-weight:inherit;
  line-height:inherit;
  letter-spacing:inherit;
}
.hero_home_contain{
  min-height:100svh;
  padding-top:var(--_spacing---section-space--large);
  padding-bottom:var(--site--margin);
  flex-flow:column;
  justify-content:space-between;
  align-items:stretch;
  display:flex;
  position:static !important;
}
.hero_home_header{
  margin-bottom:var(--_spacing---space--4);
  display:flex;
}
.hero_home_heading.u-text-style-display{
  max-width:18ch;
  margin-right:var(--_spacing---space--6);
  color:#000000 !important;
}
.hero_home_reel{
  top:auto;
  left:auto;
  right:var(--site--margin);
  bottom:calc(var(--site--margin) + 6.5rem);
  width:calc(var(--column-width--5) * .85) !important;
  transform-origin:100% 100%;
  position:absolute;
}
@media screen and (max-width:991px){
  .hero_home_reel{ width:var(--column-width--8) !important; }
}
@media screen and (max-width:767px){
  .hero_home_reel{ width:var(--column-width--10) !important; }
}
@media screen and (max-width:479px){
  .hero_home_reel{ width:var(--column-width--12) !important; }
}
@media screen and (max-width:767px){
  .hero_home_header{ flex-flow:column; gap:var(--_spacing---space--4); }
  .hero_home_reel{ position:static; width:100% !important; transform-origin:50% 100%; }
}
.hero_home_reel_contain{
  aspect-ratio:16/9;
  border-radius:var(--radius--x-small);
  justify-content:center;
  align-items:center;
  display:flex;
  position:relative;
  overflow:clip;
}
.hero_home_reel_contain video{
  width:100%;
  height:100%;
  object-fit:cover;
}
.hero_home_reel_text{
  margin-top:var(--_spacing---space--3);
}
.hero_home_bottom{
  justify-content:space-between;
  align-items:flex-end;
  flex-flow:row;
  display:flex;
  position:relative;
}
.hero_home_content{
  margin-top:var(--_spacing---section-space--main);
  flex-flow:column;
  justify-content:flex-end;
  align-items:flex-start;
  height:auto;
  display:flex;
  position:relative;
}
.hero_home_p{
  color:#000000 !important;
}
.hero_home_wrap .hero_home_p.u-text-style-large{
  max-width:29ch;
  margin-bottom:var(--_spacing---space--5);
  line-height:var(--_typography---line-height--1-3);
  font-size:clamp(1.4rem,1.28rem + 0.7vw,1.98rem);
}
.hero_home_content_btns{
  grid-column-gap:var(--_spacing---space--1);
  display:flex;
}
.hero_home_wrap .u-text-style-display{
  font-family:var(--_typography---font--secondary-family);
  font-size:var(--_text-style---font-size);
  line-height:var(--_text-style---line-height);
  font-variation-settings:"wdth" 60;
  font-weight:var(--_typography---font--secondary-black);
  letter-spacing:var(--_typography---letter-spacing--0-005em);
  text-transform:uppercase;
  font-feature-settings:normal;
  --_text-style---font-family:var(--_typography---font--primary-family);
  --_text-style---font-size:var(--_typography---font-size--display);
  --_text-style---line-height:var(--_typography---line-height--0-85);
  --_text-style---font-weight:var(--_typography---font--primary-medium);
  --_text-style---letter-spacing:var(--_typography---letter-spacing--0-03em);
  --_text-style---text-wrap:wrap;
  --_text-style---margin-top:var(--_spacing---space--7);
  --_text-style---margin-bottom:var(--_spacing---space--5);
  --_text-style---text-transform:var(--_typography---text-transform--none);
  --_text-style---trim-top:var(--_typography---font--primary-trim-top);
  --_text-style---trim-bottom:var(--_typography---font--primary-trim-bottom);
  text-wrap:var(--_text-style---text-wrap);
  display:flow-root;
}
.hero_home_wrap .u-text-style-large{
  font-family:var(--_text-style---font-family);
  font-size:var(--_text-style---font-size);
  line-height:var(--_text-style---line-height);
  font-weight:var(--_text-style---font-weight);
  letter-spacing:var(--_text-style---letter-spacing);
  --_text-style---font-family:var(--_typography---font--primary-family);
  --_text-style---font-size:var(--_typography---font-size--text-large);
  --_text-style---line-height:var(--_typography---line-height--1-3);
  --_text-style---font-weight:var(--_typography---font--primary-medium);
  --_text-style---letter-spacing:var(--_typography---letter-spacing--0-03em);
  --_text-style---text-wrap:pretty;
  --_text-style---margin-top:var(--_spacing---space--4);
  --_text-style---margin-bottom:var(--_spacing---space--4);
  --_text-style---text-transform:var(--_typography---text-transform--none);
  --_text-style---trim-top:var(--_typography---font--primary-trim-top);
  --_text-style---trim-bottom:var(--_typography---font--primary-trim-bottom);
  text-transform:var(--_text-style---text-transform,none);
  text-wrap:var(--_text-style---text-wrap);
  font-feature-settings:normal;
  display:flow-root;
}
.hero_home_wrap .u-text-style-small{
  font-family:var(--_text-style---font-family);
  font-size:var(--_text-style---font-size);
  line-height:var(--_text-style---line-height);
  font-weight:var(--_text-style---font-weight);
  letter-spacing:var(--_text-style---letter-spacing);
  --_text-style---font-family:var(--_typography---font--primary-family);
  --_text-style---font-size:var(--_typography---font-size--text-small);
  --_text-style---line-height:var(--_typography---line-height--1-3);
  --_text-style---font-weight:var(--_typography---font--primary-medium);
  --_text-style---letter-spacing:var(--_typography---letter-spacing--0-005em);
  --_text-style---text-wrap:pretty;
  --_text-style---margin-top:var(--_spacing---space--4);
  --_text-style---margin-bottom:var(--_spacing---space--4);
  --_text-style---text-transform:var(--_typography---text-transform--none);
  --_text-style---trim-top:var(--_typography---font--primary-trim-top);
  --_text-style---trim-bottom:var(--_typography---font--primary-trim-bottom);
  text-transform:var(--_text-style---text-transform,none);
  text-wrap:var(--_text-style---text-wrap);
  font-feature-settings:normal;
  display:flow-root;
}
.hero_home_wrap .u-weight-bold{
  font-family:var(--_typography---font--primary-family);
  font-weight:var(--_typography---font--primary-semibold);
}
.hero_home_wrap .u-text-transform-uppercase{text-transform:uppercase}
.hero_home_wrap .u-cover-absolute{width:100%;height:100%;position:absolute;inset:0%}
.hero_home_wrap .g_btn_main{
  grid-column-gap:var(--_spacing---space--2);
  grid-row-gap:var(--_spacing---space--2);
  border-top-left-radius:var(--radius--x-small);
  border-top-right-radius:var(--radius--x-small);
  border-bottom-left-radius:var(--radius--x-small);
  border-bottom-right-radius:var(--radius--x-small);
  color:var(--swatch--light);
  justify-content:center;
  align-items:center;
  width:fit-content;
  display:flex;
  position:relative;
  overflow:clip !important;
  padding:0;
  background-color:transparent;
  text-decoration:none;
}
.hero_home_wrap .g_btn_container{
  padding:var(--_spacing---space--1)var(--_spacing---space--2)var(--_spacing---space--1)var(--_spacing---space--1);
  grid-column-gap:var(--_spacing---space--2);
  border-radius:var(--radius--x-small);
  background-color:var(--swatch--black);
  transform-origin:50% 100%;
  border:1px solid #000;
  justify-content:center;
  align-items:center;
  display:flex;
}
.hero_home_wrap .g_btn_container.is-duplicate{
  background-color:var(--swatch--light);
  transform-origin:0 100%;
  color:var(--swatch--black);
  transform-style:preserve-3d;
  border:1px solid #000;
  position:absolute;
  inset:0%;
  transform:rotateX(28deg)rotateY(28deg)rotate(10deg)translateY(100%);
  visibility:hidden;
}
.hero_home_wrap .g_btn_text{display:block}
.hero_home_wrap .g_btn_text.u-weight-bold.u-text-transform-uppercase.u-text-style-small{
  --_text-style---font-size:var(--_typography---font-size--text-xsmall);
}
.hero_home_wrap .g_btn_cover{
  background-color:var(--swatch--light);
  border:1px solid #000;
  border-radius:.15rem;
  color:var(--swatch--black);
  justify-content:center;
  align-items:center;
  width:1.75rem;
  height:1.75rem;
  display:flex;
  position:relative;
  overflow:clip;
  flex:none;
}
.hero_home_wrap .g_btn_cover.is-duplicate{
  background-color:var(--swatch--black);
  color:var(--swatch--light);
}
.hero_home_wrap .g_btn_arrow{
  width:.75rem;
  height:.75rem;
  display:flex;
  position:relative;
}
.hero_home_wrap .g_btn_cover_arrow{width:100%;height:100%;display:block}
.hero_home_wrap .navbar_btn_default{
  z-index:1;
  color:#000;
  text-transform:uppercase;
  -webkit-user-select:none;
  user-select:none;
  place-content:center;
  padding:.75rem;
  font-size:.875rem;
  font-weight:500;
  line-height:1;
  text-decoration:none;
  display:flex;
  position:relative;
}
.hero_home_wrap .navbar_btn_container{
  z-index:1;
  padding:var(--_spacing---space--3);
  border-radius:var(--radius--x-small);
  background-color:var(--swatch--yellow);
  transform-origin:50% 100%;
  border:1px solid #000;
  justify-content:center;
  align-items:center;
  display:flex;
  position:relative;
  overflow:clip;
}
.hero_home_wrap .navbar_btn_container.is-duplicate{
  background-color:var(--swatch--black);
  transform-origin:0 100%;
  color:var(--swatch--light);
  transform-style:preserve-3d;
  border-style:none;
  position:absolute;
  inset:0%;
  transform:translateY(100%)rotateX(-28deg)rotateY(-28deg)rotate(10deg);
  visibility:hidden;
}
.hero_home_wrap .navbar_btn_text{display:block}
.hero_home_wrap .navbar_btn_text.u-text-style-small.u-text-transform-uppercase{
  --_text-style---font-size:var(--_typography---font-size--text-small);
  font-weight:900;
}
.hero_home_wrap .is-overlap{border-radius:var(--radius--x-small);padding:.1px;overflow:hidden}
.hero_home_wrap .g_btn_main .g_btn_container{
  background-color:#000000 !important;
  color:#ffffff !important;
  min-height:2.75rem !important;
}
.hero_home_wrap .g_btn_main .g_btn_container.is-duplicate{
  background-color:#008000 !important;
  color:#000000 !important;
  min-height:2.75rem !important;
}
.hero_home_wrap .navbar_btn_default .navbar_btn_container{
  background-color:#ffffff !important;
  color:#000000 !important;
}
.hero_home_wrap .navbar_btn_default .navbar_btn_container.is-duplicate{
  background-color:#008000 !important;
  color:#000000 !important;
  border:1px solid #000000 !important;
}
@media(hover:hover) and (pointer:fine){
  .hero_home_wrap .g_btn_container,
  .hero_home_wrap .g_btn_container.is-duplicate,
  .hero_home_wrap .navbar_btn_container,
  .hero_home_wrap .navbar_btn_container.is-duplicate{
    transition:transform var(--duration-fast) var(--animation-secondary), visibility 0s linear var(--duration-fast);
    will-change:transform;
  }
  .hero_home_wrap .g_btn_main:hover .g_btn_container.is-duplicate,
  .hero_home_wrap .navbar_btn_default:hover .navbar_btn_container.is-duplicate{
    transform:translateY(0%);
    visibility:visible;
    transition:transform var(--duration-fast) var(--animation-secondary), visibility 0s linear 0s;
  }
  .hero_home_wrap .g_btn_main:hover .g_btn_container,
  .hero_home_wrap .navbar_btn_default:hover .navbar_btn_container{
    transform:scale(0);
  }
}
.lines-split{display:block;overflow:hidden}
.words-split{display:inline-block}
[data-hero-wrap]{visibility:visible!important;opacity:1!important}
</style><section class="hero_home_wrap"><div class="hero_home_container"><div data-hero-wrap="" class="hero_home_contain"><div class="hero_home_header"><h1 data-split-animation="" data-split-text="" class="hero_home_heading u-text-style-display">DCVS SERVICES FOR EVERY PET<br>IN NEED</h1><div class="hero_home_reel"><div class="hero_home_reel_contain"><video src="assets/videos/DCVS%20reels%20final.mp4" autoplay="" loop="" muted="" playsinline="" webkit-playsinline="" class="g_visual_video u-cover-absolute"></video></div><div class="hero_home_reel_text u-text-style-small u-weight-bold">DCVS IN ACTION (2020—2026)</div></div></div><div class="hero_home_bottom"><div class="hero_home_content"><p data-split-animation="" data-split-text="" class="hero_home_p u-text-style-large">We connect residents, barangays, and veterinary services through one digital platform that makes animal welfare easier to access, faster to manage, and built for a safer, healthier community.</p><div class="hero_home_content_btns"><a href="petlist.php" class="navbar_btn_default is-overlap w-inline-block"><span class="navbar_btn_container"><span class="navbar_btn_text u-text-style-small u-text-transform-uppercase u-weight-bold">Want to adopt?</span></span><span aria-hidden="" class="navbar_btn_container is-duplicate"><span class="navbar_btn_text u-text-style-small u-text-transform-uppercase u-weight-bold">Want to adopt?</span></span></a><a href="<?php echo $apptUrl; ?>" class="navbar_btn_default is-overlap w-inline-block"><span class="navbar_btn_container"><span class="navbar_btn_text u-text-style-small u-text-transform-uppercase u-weight-bold">Book an Appointment</span></span><span aria-hidden="" class="navbar_btn_container is-duplicate"><span class="navbar_btn_text u-text-style-small u-text-transform-uppercase u-weight-bold">Book an Appointment</span></span></a></div></div></div></div></div></section><section data-theme-section="dark" data-stacking-cards-item="" class="gap_home_wrap"><div class="gap_home_contain"><div class="g_bg_overlay"><div class="gap_home_line_horizontal"><div class="gap_home_line_square is-1"></div><div class="gap_home_line_square is-2"></div><div class="gap_home_line_square is-3"></div></div><div class="gap_home_overlay u-grid-custom"><div class="gap_home_line"></div><div id="w-node-_95ea37ad-cb60-3f9c-8b81-20835051313b-c8fe3e53" class="gap_home_line"></div><div id="w-node-_32821c15-9e1e-2210-1e83-e2ffe4f5ce03-c8fe3e53" class="gap_home_line"></div><div id="w-node-_50c9e742-7699-54cc-3f46-fbd91f6a2339-c8fe3e53" class="gap_home_line"></div><div id="w-node-_1d3727e3-739e-0c86-bddf-51dc1e7d8554-c8fe3e53" class="gap_home_line"></div></div><div class="gap_home_line_horizontal"><div class="gap_home_line_square is-1"></div><div class="gap_home_line_square is-2"></div><div class="gap_home_line_square is-3"></div></div></div><div class="gap_home_inner"><div class="gap_home_eyebrow u-text-mono"><br/><br/><br/><br/></div><div class="gap_home_inner_content"><div class="gap_home_inner_heading-contain is-left"><h2 class="gap_home_inner_heading u-text-style-display is-left">We connect</h2></div><div class="gap_home_inner_heading-contain is-right"><h2 class="gap_home_inner_heading u-text-style-display is-right">Communities</h2></div></div><div class="gap_home_bottom"></div></div><div class="gap_home_cover"><div class="gap_home_gradient"></div><img src="assets/images/WE%20BRIDGE%20(1).jpg" loading="lazy" alt="" class="gap_home_image"/><img src="assets/images/WE%20BRIDGE%20%20%20(2).jpg" loading="lazy" alt="" class="gap_home_image"/><img src="assets/images/WE%20BRIDGE%20%20(3).jpg" loading="lazy" alt="" class="gap_home_image"/><img src="assets/images/WE%20BRIDGE%20(4).jpg" loading="lazy" alt="" class="gap_home_image"/><img src="assets/images/WE%20BRIDGE%20(5).jpg" loading="lazy" alt="" class="gap_home_image"/></div></div></section><section data-theme-section="dark" class="problems_home_wrap"><div class="problems_home_bottom"><div class="problems_home_left"><div data-wf--global-eyebrow--variant="base" class="g_eyebrow"><div class="g_eyebrow_circle"></div><div id="w-node-_01473dc5-045d-cd95-0817-52d9c52ac3d1-c52ac3cf" class="g_eyebrow_text u-text-style-large">Dasmari&#241;as City Veterinary Services partnered with</div></div></div><div class="problems_home_collection w-dyn-list"><div role="list" class="problems_home_list u-grid-custom w-dyn-items"><div role="listitem" class="problems_home_item w-dyn-item"><img src="assets/images/AKF.png" loading="lazy" width="351" height="351" alt="" class="problems_home_image"/><div class="problems_home_label u-text-mono">Animal Kingdom Foundation (AKF)</div></div><div role="listitem" class="problems_home_item w-dyn-item"><img src="assets/images/ANIMAL%20INDUSTRY.png" loading="lazy" width="351" height="351" alt="" class="problems_home_image"/><div class="problems_home_label u-text-mono">Bureau of Animal Industry (Department of Agriculture)</div></div><div role="listitem" class="problems_home_item w-dyn-item"><img src="assets/images/CARA.png" loading="lazy" width="351" height="351" alt="" class="problems_home_image"/><div class="problems_home_label u-text-mono">Compassion and Responsibility for Animals (CARA) Welfare Philippines</div></div><div role="listitem" class="problems_home_item w-dyn-item"><img src="assets/images/PARTNER.png" loading="lazy" width="351" height="351" alt="" class="problems_home_image"/><div class="problems_home_label u-text-mono">Pet Lovers Philippines, Inc.</div></div><div role="listitem" class="problems_home_item w-dyn-item"><img src="assets/images/PAWS.png" loading="lazy" width="351" height="351" alt="" class="problems_home_image"/><div class="problems_home_label u-text-mono">Philippine Animal Welfare Society (PAWS)</div></div><div role="listitem" class="problems_home_item w-dyn-item"><img src="assets/images/PAWSTURE.png" loading="lazy" width="351" height="351" alt="" class="problems_home_image"/><div class="problems_home_label u-text-mono">Pawsture</div></div><div role="listitem" class="problems_home_item w-dyn-item"><img src="assets/images/SPCA.png" loading="lazy" width="351" height="351" alt="" class="problems_home_image"/><div class="problems_home_label u-text-mono">SPCA International (Society for the Prevention of Cruelty to Animals International)</div></div><div role="listitem" class="problems_home_item w-dyn-item"><img src="assets/images/WORLD%20ANIMAL%20PROTECTION.png" loading="lazy" width="351" height="351" alt="" class="problems_home_image"/><div class="problems_home_label u-text-mono">World Animal Protection</div></div></div></div></div></section><section id="process" data-theme-section="dark" class="process_home_wrap"><div class="process_css w-embed"><style>
  .process_home_content-listlist {
    counter-reset: item-counter;
  }

  .process_home_content-item {
    counter-increment: item-counter;
  }

  .process_home_content-item .process_home_content_span::before {
    content: counter(item-counter, decimal-leading-zero);
  }

  .process_index_span {
    transform-origin: left center;
    transform: scaleX(0.3);
    opacity: 0.2;
  }

  .process_index_text {
    opacity: 0.2;
  }
  .process_home_heading.u-text-style-display {
    color: #ffffff;
    text-align: center;
  }
  .process_home_micrographic {
    display: none;
  }
</style></div><h2 class="process_home_heading u-text-style-display">How DCVS Works</h2><div class="process_home_content w-dyn-list"><div role="list" class="process_home_content-list w-dyn-items"><div role="listitem" class="process_home_content-item w-dyn-item"><div class="process_home_content-left"><div class="process_home_content_index u-text-mono"><div class="process_home_micrographic"><div class="works_home_content_ss u-text-style-micro">Step</div><div class="process_micrographic_inner"><div class="process_micrographic_circle"></div></div><div class="process_home_content_span u-text-style-micro">ÃƒÂ¢Ã¢â€šÂ¬Ã…Â½ </div></div></div><div class="process_home_content_inner"><h3 class="process_home_content_heading u-text-style-h6">Every Service.</h3><p class="process_home_content-p u-text-style-small">From pet registration and appointment booking to animal welfare reporting, DCVS brings every essential veterinary service into one digital platform. Built to simplify requests, improve accessibility, and strengthen collaboration between residents, barangays, and the City Veterinary Office.</p></div></div><a data-video="playpause" data-cursor-text="Watch Feature Overview" data-cursor-hover="" aria-label="Watch Feature Overview" class="process_home_video w-inline-block"><img src="assets/images/service%20image.jpg" loading="lazy" alt="" class="overview_home_video u-ratio-16-9 u-background-skeleton"/><div class="process_home_cta u-text-mono is-desktop u-sr-only">Watch Feature Overview</div><div class="process_home_cta u-text-mono">Watch Feature Overview</div></a></div><div role="listitem" class="process_home_content-item w-dyn-item"><div class="process_home_content-left"><div class="process_home_content_index u-text-mono"><div class="process_home_micrographic"><div class="works_home_content_ss u-text-style-micro">Step</div><div class="process_micrographic_inner"><div class="process_micrographic_circle"></div></div><div class="process_home_content_span u-text-style-micro">ÃƒÂ¢Ã¢â€šÂ¬Ã…Â½ </div></div></div><div class="process_home_content_inner"><h3 class="process_home_content_heading u-text-style-h6">One Dashboard.</h3><p class="process_home_content-p u-text-style-small">Sign in to a personalized dashboard where pets, reports, appointments, notifications, announcements, and account information are organized into one secure workspace. Everything you need stays connected and easy to manage.</p></div></div><a data-video="playpause" data-cursor-text="Watch Dashboard Tour" data-cursor-hover="" aria-label="Watch Dashboard Tour" class="process_home_video w-inline-block"><video src="assets/videos/dashboard%20video.mp4" loop="" muted="" playsinline=" " STYLE="" preload="none" class="overview_home_video u-ratio-16-9 u-background-skeleton"></video><div class="process_home_cta u-text-mono is-desktop u-sr-only">Watch Dashboard Tour</div><div class="process_home_cta u-text-mono">Watch Dashboard Tour</div></a></div><div role="listitem" class="process_home_content-item w-dyn-item"><div class="process_home_content-left"><div class="process_home_content_index u-text-mono"><div class="process_home_micrographic"><div class="works_home_content_ss u-text-style-micro">Step</div><div class="process_micrographic_inner"><div class="process_micrographic_circle"></div></div><div class="process_home_content_span u-text-style-micro">ÃƒÂ¢Ã¢â€šÂ¬Ã…Â½ </div></div></div><div class="process_home_content_inner"><h3 class="process_home_content_heading u-text-style-h6">Every Case.</h3><p class="process_home_content-p u-text-style-small">View verified reports through an interactive geo-mapping system designed for real-time monitoring across Dasmariñas. Help identify affected areas, monitor ongoing cases, and support faster, data-driven response by DCVS and barangays.</p></div></div><a data-video="playpause" data-cursor-text="Watch Map Demo" data-cursor-hover="" aria-label="Watch Map Demo" class="process_home_video w-inline-block"><video src="assets/videos/geo%20map%20video.mp4" loop="" muted="" playsinline=" " STYLE="" preload="none" class="overview_home_video u-ratio-16-9 u-background-skeleton"></video><div class="process_home_cta u-text-mono is-desktop u-sr-only">Watch Map Demo</div><div class="process_home_cta u-text-mono">Watch Map Demo</div></a></div></div></div></section><div class="cta_work_wrap"><section data-theme-section="dark" class="cta_home_wrap"><div class="cta_home_contain"><div class="cta_home_inner"><div class="cta_home_header"><h2 class="cta_home_heading u-text-style-display u-sr-only">Ready to build an experience that moves people?</h2><!--$--><div class="cta_home_heading_contain"><div class="cta_home_heading u-text-style-display">Let&#x27;s build</div><div class="cta_home_heading u-text-style-display is-2">A safer</div><div class="cta_home_heading u-text-style-display is-3">Community</div><!--$--><span class="cta_heading_inner"><div class="cta_home_heading u-text-style-display is-4 is-arrow"></div><div class="cta_home_heading u-text-style-display is-4">Together</div></span><!--/$--></div><!--/$--><a data-btn-default="" data-wf--global-button-main--variant="large" class="g_btn_main w-variant-c2ee8580-9f06-5e9a-1461-dda4efd8c449 w-inline-block" href="login.php"><div class="g_btn_text_contain w-variant-c2ee8580-9f06-5e9a-1461-dda4efd8c449"><div class="g_btn_text u-text-style-small u-text-trim-off w-variant-c2ee8580-9f06-5e9a-1461-dda4efd8c449">Get Started</div></div><div class="g_btn_aside_wrap w-variant-c2ee8580-9f06-5e9a-1461-dda4efd8c449"><div class="g_btn_aside_bg w-variant-c2ee8580-9f06-5e9a-1461-dda4efd8c449"></div><!--$--><svg xmlns="http://www.w3.org/2000/svg" width="100%" viewBox="0 0 12 12" fill="none" class="g_btn_svg w-variant-c2ee8580-9f06-5e9a-1461-dda4efd8c449"><!--$--><path d="M8.90954 9.09046L9 3L2.90954 3.09046L2.90213 4.32367L6.86437 4.25391L2.55914 8.55914L3.44086 9.44086L7.74609 5.13563L7.68708 9.10862L8.90954 9.09046Z" fill="currentColor"></path><!--/$--></svg><!--/$--><!--$--><svg xmlns="http://www.w3.org/2000/svg" width="100%" viewBox="0 0 12 12" fill="none" class="g_btn_svg w-variant-c2ee8580-9f06-5e9a-1461-dda4efd8c449 is-absolute"><!--$--><path d="M8.90954 9.09046L9 3L2.90954 3.09046L2.90213 4.32367L6.86437 4.25391L2.55914 8.55914L3.44086 9.44086L7.74609 5.13563L7.68708 9.10862L8.90954 9.09046Z" fill="currentColor"></path><!--/$--></svg><!--/$--></div></a></div></div><div class="cta_home_bottom"><div data-wf--spacer--section-space="main" class="u-section-spacer w-variant-60a7ad7d-02b0-6682-95a5-2218e6fd1490"></div><div class="cta_home_proof"><div class="cta_home_awards"><!--$--><img src="assets/images/DCVS%20AWARDS.png" alt="DCVS AWARDS" loading="lazy" class="cta_home_svgs"><!--/$--><!--$--><img src="assets/images/ANIMAL%20WELFARE%20AWARDS.png" alt="ANIMAL WELFARE AWARDS" loading="lazy" class="cta_home_svgs is-cssda"><!--/$--><!--$--><img src="assets/images/PUBLIC%20SERVICE%20AWARDS.png" alt="PUBLIC SERVICE AWARDS" loading="lazy" class="cta_home_svgs"><!--/$--></div><blockquote class="cta_home_testimonial_message u-text-style-h5">A modern platform that makes animal welfare services more accessible, transparent, and connected for every community.</blockquote><div class="cta_home_testimonee u-text-mono">&#8212; Dasmari&#241;as City Veterinary Services</div></div></div></div><div data-start="top bottom" data-scroll-container="" data-target-translate="150" class="cta_home_cover"><div class="cta_home_overlay"></div><img data-translate-hero="true" loading="lazy" alt="Dasmarinas City Veterinary Services" src="assets/images/footer%20image.jpg" class="cta_home_visual"/></div></section></div><button id="to-top" data-hover-highlight="link" class="footer_bottom_span is-top" onclick="window.scrollTo({top:0,behavior:'smooth'})" style="position:fixed;right:1.25rem;bottom:1.25rem;z-index:50;padding:.75rem 1rem;border-radius:999px;background:#080807;color:#ffffff;border:1px solid rgba(255,255,255,0.25);box-shadow:0 8px 24px rgba(0,0,0,0.28);font:inherit;font-weight:600;cursor:pointer;">Back to top</button><div class="collection-list-wrapper w-dyn-list"><div data-count-group="work" role="list" class="w-dyn-items"><div data-count-item="" role="listitem" class="w-dyn-item"></div><div data-count-item="" role="listitem" class="w-dyn-item"></div><div data-count-item="" role="listitem" class="w-dyn-item"></div><div data-count-item="" role="listitem" class="w-dyn-item"></div><div data-count-item="" role="listitem" class="w-dyn-item"></div><div data-count-item="" role="listitem" class="w-dyn-item"></div><div data-count-item="" role="listitem" class="w-dyn-item"></div></div></div></main></div><script src="https://d3e54v103j8qbb.cloudfront.net/js/jquery-3.5.1.min.dc5e7f18c8.js?site=68b652bbd6c64a44c8fe3e5e" type="text/javascript" integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0=" crossorigin="anonymous"></script><script src="https://cdn.prod.website-files.com/68b652bbd6c64a44c8fe3e5e/js/webflow.a0aa6ca1.b7683852b8a60d8e.js" type="text/javascript" integrity="sha384-BrZ5RLWViy0IN9p2miOlvxmv2gP1sT/YqoMSDJznd6k1tb7t0mPZus3psGWfkx5f" crossorigin="anonymous"></script><script src="https://cdn.prod.website-files.com/gsap/3.15.0/gsap.min.js" type="text/javascript"></script><script src="https://cdn.prod.website-files.com/gsap/3.15.0/ScrollTrigger.min.js" type="text/javascript"></script><script src="https://cdn.prod.website-files.com/gsap/3.15.0/SplitText.min.js" type="text/javascript"></script><script src="https://cdn.prod.website-files.com/gsap/3.15.0/CustomEase.min.js" type="text/javascript"></script><script src="https://cdn.prod.website-files.com/gsap/3.15.0/Flip.min.js" type="text/javascript"></script><script type="text/javascript">gsap.registerPlugin(ScrollTrigger,SplitText,CustomEase,Flip);</script><script src="https://cdn.jsdelivr.net/npm/three@0.160.1/build/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/lenis@1/dist/lenis.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/howler@2.2.4"></script>
<script src="https://cdn.jsdelivr.net/npm/@barba/core@2/dist/barba.umd.js"></script>
<script>
(function(){
  var isStaging = window.location.host.includes("webflow.io");
  var base = "https://cdn.odyn.dev";
  var env = isStaging ? "staging" : "p";
  var jsSrc = base + "/" + env + "/" + "3pc9/bundle.js";
  var script = document.createElement("script");
  function keepCommunityHeadingPlain(){
    var section = document.querySelector('.gap_home_wrap');
    if (section) {
      section.removeAttribute('data-stacking-cards-item');
      section.querySelectorAll('.gap_home_inner_heading').forEach(function(heading){
        var text = heading.textContent.replace(/\s+/g,' ').trim();
        heading.replaceChildren(document.createTextNode(text));
      });
      var cover = section.querySelector('.gap_home_cover');
      if (cover) cover.style.clipPath = 'inset(0% 0% 0% 0% round 0.25rem)';
      if (cover && window.gsap) {
        window.gsap.getTweensOf(cover).forEach(function(tween){
          var timeline = tween.parent;
          var trigger = timeline && timeline.scrollTrigger;
          if (trigger && (trigger.vars.trigger === '.gap_home_wrap' || trigger.vars.trigger === section)) {
            trigger.kill();
          }
          tween.kill();
        });
      }
    }
    var heroTitle = document.querySelector('.hero_home_heading');
    var titleText = heroTitle && heroTitle.firstChild;
    if (heroTitle && !heroTitle.querySelector('.hero-primary-accent') && titleText && titleText.nodeType === Node.TEXT_NODE && titleText.textContent.indexOf('DCVS') === 0) {
      titleText.textContent = titleText.textContent.slice(4);
      var accent = document.createElement('span');
      accent.className = 'hero-primary-accent';
      accent.textContent = 'DCVS';
      heroTitle.insertBefore(accent, titleText);
    }
  }
  keepCommunityHeadingPlain();
  script.onload = keepCommunityHeadingPlain;
  script.src = jsSrc;
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function() { document.head.appendChild(script); });
  } else {
    document.head.appendChild(script);
  }
})();
</script>
<style>
  .hero_home_wrap .hero-primary-accent{color:#000000!important;}
  .gap_home_wrap,
  .problems_home_wrap,
  .process_home_wrap,
  .process_home_wrap .process_home_content,
  .process_home_wrap .process_home_content-item,
  .process_home_wrap .process_home_content-left{
    background-color:#ffffff!important;
    color:#333333!important;
  }
  .gap_home_wrap .g_bg_overlay,
  .problems_home_wrap .g_bg_overlay,
  .process_home_wrap .g_bg_overlay{
    background-color:#ffffff!important;
  }
  .gap_home_wrap :is(.gap_home_inner_heading,.g_eyebrow_text,.gap_home_p),
  .problems_home_wrap :is(.g_eyebrow_text,.problems_home_item,.problems_home_label),
  .process_home_wrap :is(.process_home_heading,.process_home_content_heading,.process_home_content-p,.process_home_content_span,.process_home_cta,.process_index_text,.process_index_span){
    color:#333333!important;
  }
  .gap_home_wrap :is(.gap_home_inner_heading,.g_eyebrow_text),
  .problems_home_wrap .g_eyebrow_text,
  .process_home_wrap .process_home_heading,
  .process_home_wrap .process_home_content_heading{
    color:#000000!important;
  }
  .gap_home_wrap :is(.gap_home_line_horizontal,.gap_home_line_vertical),
  .problems_home_wrap :is(.problems_home_item,.g_eyebrow_circle),
  .process_home_wrap :is(.process_home_content-item,.process_home_content_span,.process_micrographic_circle){
    border-color:#000000!important;
  }
  .process_home_wrap,
  .process_home_wrap *{
    border:none!important;
  }
  .gap_home_wrap{height:auto!important;min-height:100svh;}
  .gap_home_wrap .gap_home_contain{position:relative;top:auto;height:auto;min-height:100svh;}
  .gap_home_wrap .gap_home_inner{height:auto;min-height:100svh;}
  .gap_home_wrap .gap_home_inner_content{left:0;right:0;width:100%;justify-content:center;column-gap:clamp(8px,1.4vw,20px);}
  .gap_home_wrap .gap_home_inner_heading-contain,
  .gap_home_wrap .gap_home_inner_heading-contain.is-left,
  .gap_home_wrap .gap_home_inner_heading-contain.is-right{width:max-content!important;max-width:none!important;flex:0 0 auto;overflow:visible;transform:none!important;}
  .gap_home_wrap .gap_home_inner_heading{width:max-content!important;max-width:none!important;white-space:nowrap!important;}
  .gap_home_wrap .gap_home_inner_heading{color:#045e1f!important;}
  .gap_home_wrap .gap_home_cover{width:min(450px,calc(100vw - 24px))!important;max-width:none!important;aspect-ratio:4/5;transform:translate(-50%,-50%)!important;clip-path:inset(0 round .25rem)!important;transition:none!important;animation:none!important;}
  @media screen and (max-width:767px){
    .gap_home_wrap .gap_home_inner_content{top:17%!important;flex-flow:row nowrap!important;column-gap:6px;}
    .gap_home_wrap .gap_home_inner_heading-contain,
    .gap_home_wrap .gap_home_inner_heading-contain.is-left,
    .gap_home_wrap .gap_home_inner_heading-contain.is-right{flex:0 0 auto;}
    .gap_home_wrap .gap_home_inner_heading{font-size:clamp(1.25rem,7.2vw,1.75rem)!important;}
    .gap_home_wrap .gap_home_cover{width:min(370px,calc(100vw - 24px))!important;max-width:none!important;}
  }
  .problems_home_item {
    padding-bottom: 2rem;
  }
    .problems_home_item {
    padding-bottom: 2rem;
  }
  .cta_home_awards .cta_home_svgs {
    width: 19vw !important;
    min-width: 11rem !important;
    max-width: 19rem !important;
    height: auto !important;
    object-fit: contain;
  }
</style>
<script>
(function(){
  if (!window.gsap) return;
  if (!window.CustomEase || !window.SplitText) return;
  var done = false;

  try {
    CustomEase.create("ease-secondary", "0.31, 0.75, 0.22, 1");
    CustomEase.create("ease-transition", "0.16, 1, 0.35, 1");
  } catch(e){}

  function animateRest(hero){
    var mm = gsap.matchMedia();
    mm.add("(min-width: 992px)", function(){
      gsap.timeline()
        .from(".hero_home_content_btns > *", { autoAlpha: 0, yPercent: 100, ease: "ease-transition", duration: 1, stagger: 0.1 }, "+=0.1")
        .from(".hero_home_reel", { yPercent: 120, ease: "ease-transition", duration: 1 }, "-=0.5");
    });
    mm.add("(max-width: 991px)", function(){
      gsap.timeline()
        .from(".hero_home_content_btns > *", { autoAlpha: 0, yPercent: 100, ease: "ease-transition", duration: 1, stagger: 0.1 }, "+=0.1")
        .from(".hero_home_reel", { autoAlpha: 0, yPercent: 40, ease: "ease-transition", duration: 1 }, "-=0.5");
    });
  }

  function animateHero(){
    if(done) return;
    done = true;
    var hero = document.querySelector(".hero_home_wrap");
    if(!hero) return;
    gsap.set("[data-hero-wrap]", { autoAlpha: 1 });

    var tries = 0;
    (function attempt(){
      var lines = gsap.utils.toArray(hero.querySelectorAll("[data-split-text] .lines-split"));
      if(lines.length){
        gsap.timeline().from(lines, { yPercent: 150, rotate: 0.001, ease: "ease-secondary", duration: 1, stagger: 0.0825 });
        animateRest(hero);
        return;
      }
      if(tries < 60){ tries++; setTimeout(attempt, 50); return; }
      try {
        hero.querySelectorAll("[data-split-text]").forEach(function(el){
          if(el.querySelector(".lines-split")) return;
          var split = SplitText.create(el, {
            type: "lines",
            linesClass: "lines-split",
            mask: "lines",
            autoSplit: true,
            ignore: ".no-split"
          });
          el.textSplit = split;
        });
      } catch(err){}
      var flines = gsap.utils.toArray(hero.querySelectorAll("[data-split-text] .lines-split"));
      if(flines.length){
        gsap.timeline().from(flines, { yPercent: 150, rotate: 0.001, ease: "ease-secondary", duration: 1, stagger: 0.0825 });
      }
      animateRest(hero);
    })();
  }

  if(document.fonts && document.fonts.ready){
    document.fonts.ready.then(animateHero);
  } else {
    window.addEventListener("load", animateHero);
  }
  if(document.readyState === "complete"){ animateHero(); }
})();
</script>
</body></html>