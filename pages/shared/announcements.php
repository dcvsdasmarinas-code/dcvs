<!DOCTYPE html>
<?php
require_once __DIR__ . '/../../includes/app-base.php';
$appBaseUrl = dcvsAppBaseUrl();
require_once __DIR__ . '/../../db.php';
$currentPage = 'updates'; $homePage = 'landing-page.php';
$announcements = [];
$conn->query("UPDATE announcements SET status='published', updated_at=NOW() WHERE status='scheduled' AND publish_at IS NOT NULL AND publish_at <= NOW() AND is_active=1");
$aq = $conn->query(
    "SELECT title, location, content, category, subject, link_url, photo_url,
            status, created_at, publish_at, is_event, event_date, event_end_date
     FROM announcements
     WHERE is_active=1
       AND (status='published' OR (status='scheduled' AND publish_at IS NOT NULL AND publish_at <= NOW()))
       AND archived_at IS NULL
     ORDER BY COALESCE(publish_at, created_at) DESC"
);
if ($aq) { while ($rr = $aq->fetch_assoc()) $announcements[] = $rr; }
$publicBarangayNames = [];
$publicBarangaysQuery = $conn->query("SELECT barangay_id, barangay_name FROM barangays");
if ($publicBarangaysQuery) {
    while ($barangay = $publicBarangaysQuery->fetch_assoc()) {
        $publicBarangayNames[(int)$barangay['barangay_id']] = $barangay['barangay_name'];
    }
}
$publicSubjectLabels = [
    'animal_health' => 'Animal Health',
    'services_programs' => 'Services and Programs',
    'events' => 'Events',
    'general_notices' => 'General Notices'
];
$normalizePublicBarangayText = static function (string $value): string {
    $value = strtolower($value);
    $value = preg_replace('/\biv\b/', '4', $value);
    $value = preg_replace('/\biii\b/', '3', $value);
    $value = preg_replace('/\bii\b/', '2', $value);
    $value = preg_replace('/\bi\b/', '1', $value);
    return preg_replace('/\s+/', ' ', trim($value));
};
foreach ($announcements as &$announcement) {
    $targets = [];
    $location = trim((string)($announcement['location'] ?? ''));
    if ($location !== '') {
        $normalizedLocation = $normalizePublicBarangayText($location);
        foreach ($publicBarangayNames as $barangayId => $barangayName) {
            $normalizedName = $normalizePublicBarangayText((string)$barangayName);
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($normalizedName, '/') . '(?![\p{L}\p{N}])/u', $normalizedLocation)) {
                $targets[] = ['id' => (int)$barangayId, 'name' => (string)$barangayName];
            }
        }
    }
    $announcement['_public_targets'] = $targets;
    $announcement['_public_all_barangays'] = strcasecmp($location, 'all barangays') === 0;
    $announcement['_public_category_label'] = $publicSubjectLabels[(string)($announcement['subject'] ?? '')]
        ?? 'General Notices';
}
unset($announcement);
function annf($c){ return $c === 'advisory' ? 'advisories' : 'announcements'; }
function annlabel($c){ return $c === 'advisory' ? 'Advisory' : 'Announcement'; }
function annAudience($announcement, $barangayNames) {
    return '';
}
$ann_ct = count($announcements);
?>
<!-- Last Published: Sun Jul 26 2026 10:32:10 GMT+0000 (Coordinated Universal Time) --><!--$-->
<html data-wf-domain="bymonolog.com" data-wf-page="69ec4c79294b43d640e8e4bd" data-wf-site="68b652bbd6c64a44c8fe3e5e" lang="en"><!--$--><head><meta charset="utf-8"/><!--$--><link href="https://cdn.prod.website-files.com" rel="preconnect" crossorigin="anonymous"/><!--/$--><title>Public Service Updates | DasmariÃ±as City Veterinary Services</title><meta content="Announcements and advisories from DasmariÃ±as City Veterinary Services." name="description"/><meta content="Announcements and advisories from DasmariÃ±as City Veterinary Services." property="og:description"/><meta content="https://cdn.prod.website-files.com/68b652bbd6c64a44c8fe3e5e/6a4df8b2aef24dbe74b85600_OG.jpg" property="og:image"/><meta content="Announcements and advisories from DasmariÃ±as City Veterinary Services." name="twitter:description"/><meta property="og:type" content="website"/><meta content="summary_large_image" name="twitter:card"/><meta content="width=device-width, initial-scale=1" name="viewport"/><!--$--><link href="https://cdn.prod.website-files.com/68b652bbd6c64a44c8fe3e5e/css/bym0n0l0g.webflow.shared.e9a9b6790.min.css" rel="stylesheet" type="text/css" integrity="sha384-6am2eQ02Uw2J+mjX+sKd9mVOysbVCqZFkaXNYM4zRnptppiwgN6myyKrhcq7ME79" crossorigin="anonymous"/><!--/$--><script type="text/javascript">!function(o,c){var n=c.documentElement,t=" w-mod-";n.className+=t+"js",("ontouchstart"in o||o.DocumentTouch&&c instanceof DocumentTouch)&&(n.className+=t+"touch")}(window,document);</script><!--$--><!--/$--><!--$--><!--/$--><!--$--><!--/$--><!--$--><!--/$--><script>(function(w,i,g){w[g]=w[g]||[];if(typeof w[g].push=='function')w[g].push.apply(w[g],Array.isArray(i)?i:[i]);})(window,['G-NZ1SEFWY0E'],'google_tags_first_party');</script><script async="" src="/v2645sy3qzdjNjhiNjUyYmJkNmM2NGE0NGM4ZmUzZTVl/OZ3ljJ_w_BAmsBzWVeD8lfyivfQ"></script><script type="text/javascript">window.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}gtag('set', 'developer_id.dZGVlNj', true);gtag('set', 'developer_id.dYWYxNW', true);gtag('js', new Date());gtag('config', 'G-NZ1SEFWY0E');</script><style>
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

  [data-animate],
  .navbar_wrap,
  [data-split='heading'],
  .g_eyebrow {
    visibility: hidden;
    opacity: 0;
  }

  section[data-animate],
  section[data-animate] * {
    visibility: visible !important;
    opacity: 1 !important;
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
@font-face{font-family:Khteka;src:url(https://cdn.prod.website-files.com/68b652bbd6c64a44c8fe3e5e/6968b3919a24a99e002f91dc_KHTeka-Medium.woff2)format("woff2");font-weight:500;font-style:normal;font-display:swap}
@font-face{font-family:"Suisse Mono";src:url(https://cdn.prod.website-files.com/68b652bbd6c64a44c8fe3e5e/69c69d75a0cda52c270e65bf_SuisseIntlMono-Regular-WebXL.woff2)format("woff2");font-weight:400;font-style:normal;font-display:swap}
</style><style>
:root { --primary: #045E1FD9; --ink: #045E1FD9; --muted: #4a5672; --transition: cubic-bezier(0.25,0.65,0.35,1); }
.site-header { position:fixed; top:0; left:0; right:0; z-index:2147483000; padding:0.8rem 0; transform:none!important; }
.navbar { display:flex; align-items:center; gap:2rem; font-family:"Inter","Segoe UI",sans-serif!important; width:min(1200px,92vw); margin:0 auto; padding:0.85rem 1.5rem; border-radius:999px; background:rgba(255,255,255,0.82)!important; -webkit-backdrop-filter:blur(24px) saturate(1.8)!important; backdrop-filter:blur(24px) saturate(1.8)!important; border:1px solid rgba(255,255,255,0.65); box-shadow:0 24px 48px rgba(0,0,0,0.12); position:relative; transition:background 0.3s var(--transition),box-shadow 0.3s var(--transition),border 0.3s var(--transition),transform 0.3s var(--transition); }
.brand-logo { display:block; object-fit:contain; }
.navbar::before, .navbar::after { display:none; }
.navbar.nav-scrolled { background:rgba(255,255,255,0.88); border-color:rgba(0,0,0,0.12); box-shadow:0 26px 52px rgba(0,0,0,0.1); }
.nav-links { display:flex; align-items:center; justify-content:center; gap:1.5rem; list-style:none; margin:0; padding:0; flex:1; }
.nav-links a { font-family:"Inter","Segoe UI",sans-serif!important; font-weight:500!important; font-size:16px!important; letter-spacing:-0.01em!important; line-height:1.5!important; text-decoration:none!important; border-bottom:none!important; text-transform:none!important; padding:6px 16px; border-radius:8px; color:#1a2a3a!important; transition:color 0.25s var(--transition),background 0.25s var(--transition); }
.nav-links a::after { display:none!important; content:none!important; }
.nav-links a:hover, .nav-links a:focus-visible { background:rgba(4,94,31,0.06); color:#045e1f!important; }
.nav-links a.active { background:rgba(4,94,31,0.1); color:#045e1f!important; }
.nav-item { position:relative; margin:0!important; }
.dropdown-toggle { cursor:pointer; display:flex; align-items:center; gap:4px; padding:6px 16px; border-radius:8px; color:#1a2a3a; background:none!important; border:none!important; font-family:"Inter","Segoe UI",sans-serif!important; font-weight:500!important; font-size:16px!important; letter-spacing:-0.01em!important; text-decoration:none!important; transition:background 0.2s,color 0.2s; }
.dropdown-toggle:hover { background:rgba(4,94,31,0.06); color:#045e1f; }
.dropdown-toggle.active { background:rgba(4,94,31,0.1)!important; color:#045e1f!important; }
.dropdown-toggle .arrow { font-size:10px; transition:transform 0.3s var(--transition); color:inherit!important; }
.nav-item.open .dropdown-toggle .arrow { transform:rotate(180deg); }
.dropdown-menu { position:absolute; top:calc(100% + 8px); left:0; background:#ffffff; border-radius:12px; padding:6px 0; min-width:190px; box-shadow:0 20px 48px rgba(0,0,0,0.12); opacity:0; visibility:hidden; transform:translateY(8px); transition:all 0.25s ease; z-index:2147483000; border:1px solid rgba(0,0,0,0.06); }
.nav-item:hover .dropdown-menu, .nav-item.open .dropdown-menu { opacity:1; visibility:visible; transform:translateY(0); }
.dropdown-menu a { display:block; padding:10px 20px; font-size:14px!important; font-family:"Inter","Segoe UI",sans-serif!important; font-weight:500!important; letter-spacing:-0.01em!important; text-decoration:none!important; color:#1a2a3a; white-space:nowrap; }
.dropdown-menu a::after { display:none!important; content:none!important; }
.dropdown-menu a:hover { background:rgba(4,94,31,0.06); color:#045e1f; }
.nav-toggle { display:none; flex-direction:column; gap:5px; background:transparent; border:none; cursor:pointer; padding:0; }
.nav-toggle span { width:22px; height:2px; background:#1a2a3a; border-radius:999px; transition:transform 0.3s var(--transition),opacity 0.3s var(--transition); }
.nav-login-btn { font-family:"Inter","Segoe UI",sans-serif!important; font-weight:600!important; font-size:0.88rem!important; letter-spacing:-0.01em!important; padding:8px 22px; border-radius:8px; background:#045e1f!important; color:#fff!important; white-space:nowrap; transition:background 0.2s; flex-shrink:0; text-decoration:none!important; }
.nav-login-btn:hover { background:#043012!important; }
.nav-login-btn::after { display:none!important; content:none!important; }
@media (max-width:1100px) { .navbar { display:flex; align-items:center; gap:2rem; font-family:"Inter","Segoe UI",sans-serif!important; width:min(1200px,92vw); margin:0 auto; padding:0.85rem 1.5rem; border-radius:24px; background:rgba(255,255,255,0.82)!important; -webkit-backdrop-filter:blur(24px) saturate(1.8)!important; backdrop-filter:blur(24px) saturate(1.8)!important; border:1px solid rgba(255,255,255,0.65); box-shadow:0 24px 48px rgba(0,0,0,0.12); position:relative; transition:background 0.3s var(--transition),box-shadow 0.3s var(--transition),border 0.3s var(--transition),transform 0.3s var(--transition); } .nav-links { position:fixed; inset:90px 1.5rem auto 1.5rem; background:#ffffff; border-radius:24px; padding:1.8rem; flex-direction:column; align-items:flex-start; gap:1rem; box-shadow:0 24px 60px rgba(18,39,84,0.25); transform:translateY(-20px); opacity:0; pointer-events:none; transition:opacity 0.3s var(--transition),transform 0.3s var(--transition); max-height:calc(100vh - 110px); overflow-y:auto; } .nav-links.open { transform:translateY(0); opacity:1; pointer-events:auto; } .nav-toggle { display:flex; } .nav-login-btn { display:inline-block; } }
    .transition_screen { display:none!important; }
    body { overflow:auto!important; background:#ffffff!important; }
    html { background-color:#ffffff!important; }
    .page_wrap { visibility:visible!important; }
    .page_main { visibility:visible!important; }
    .hero_work_wrap { background-color:#ffffff!important; }
</style></style>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
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
<link rel="stylesheet" href="<?php echo htmlspecialchars($appBaseUrl . '/assets/css/site-navbar.css?v=' . (int)filemtime(__DIR__ . '/../../assets/css/site-navbar.css'), ENT_QUOTES, 'UTF-8'); ?>" />
</head><!--/$--><body data-barba="wrapper" data-navigation-status="is-closed" data-about-status="is-closed" data-theme-nav="light" data-scroll-time="0"><?php include __DIR__ . '/../../includes/landing-navbar.php'; ?><div class="transition_screen"></div><div data-cursor="" class="cursor_wrap"><div class="cursor-bubble"><!--$--><span data-cursor-text-target="" class="cursor-bubble__text u-text-style-main">Hello world</span><!--/$--></div><div class="cursor_css w-embed"><style>
  @media (hover: hover) and (pointer: fine) {


  /* Ã¢â€â‚¬Ã¢â€â‚¬ Bubble base Ã¢â€â‚¬Ã¢â€â‚¬ */
  [data-cursor] .cursor-bubble {
    clip-path: inset(0% 100% 0% 0% round 6px);
    opacity: 0;
    transform: translateX(0%) scale(0.92) rotate(0.001deg);
    transition:
      clip-path 0.45s cubic-bezier(0.16, 1, 0.3, 1),
      opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1),
      transform 0.45s cubic-bezier(0.16, 1, 0.3, 1);
  }

  /* Ã¢â€â‚¬Ã¢â€â‚¬ Active: wipe open left Ã¢â€ â€™ right Ã¢â€â‚¬Ã¢â€â‚¬ */
  [data-cursor='active'] .cursor-bubble {
    clip-path: inset(0% 0% 0% 0% round 6px);
    opacity: 1;
    transform: translateX(0%) scale(1) rotate(0.001deg);
  }

  /* Ã¢â€â‚¬Ã¢â€â‚¬ Active edge: flip to left side Ã¢â€â‚¬Ã¢â€â‚¬ */
  [data-cursor='active-edge'] .cursor-bubble {
    clip-path: inset(0% 0% 0% 0% round 6px);
    opacity: 1;
    transform: translateX(-100%) scale(1) rotate(0.001deg);
  }

  /* Ã¢â€â‚¬Ã¢â€â‚¬ Content inside bubble Ã¢â€â‚¬Ã¢â€â‚¬ */
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

</style></div><!--$--><button data-close-modal="" id="" class="about_overlay_close"><!--$--><span class="about_overlay_text u-sr-only">Close about modal</span><!--/$--></button><!--/$--><div class="about_modal_wrap"><!--$--><button id="" data-close-modal="" data-btn-default="" class="g_btn_wrapper"><!--$--><span data-btn-default="" id="" class="g_btn_main is-close"><div class="g_btn_text_contain"><div class="g_btn_text u-text-style-small u-text-trim-off is-about">Close</div></div><div class="g_btn_aside_wrap"><div class="g_btn_aside_bg"></div><!--$--><span class="g_btn_svg u-text-mono">esc</span><!--/$--><!--$--><span class="g_btn_svg is-absolute u-text-mono">esc</span><!--/$--></div></span><!--/$--></button><!--/$--><div class="about_modal_contain"><div class="about_modal_top"><div class="g_eyebrow_about"><div class="g_eyebrow_circle"></div><div id="w-node-_269d15e3-bea6-e335-54d1-1b62770afd60-a51e8985" class="g_eyebrow_text u-text-style-large is-about">About the studio</div></div></div><div class="about_modal_content"><p data-split-reveal="lines" data-split="heading" class="about_modal_p u-text-style-h5">We are DasmariÃ±as City Veterinary Services, dedicated to making access to animal welfare services simple, transparent, and available to everyone. because their presence never caught up to who they&#x27;d become despite the scale of their ambition.<!--$--><br/><!--/$--><!--$--><br/><!--/$-->That gap became an obsession. I&#x27;ve spent years breaking down what separates forgettable digital presence from work that actually moves people, thinking that&#x27;s reached over 60,000 creatives and shapes every project we take on.<!--$--><br/><!--/$--><!--$--><br/><!--/$-->When we work with founders, we are immersed in your story, ruthless about what moves people, and built to close the gap between who you are and how the world sees you.</p><div class="about_modal_cover"><div class="about_modal_deco_braille"><div class="about_modal_deco_braille"><!--$--><svg xmlns="http://www.w3.org/2000/svg" width="100%" viewBox="0 0 29 11" fill="none" class="about_modal_braille"><!--$--><path d="M14 1.5C14 2.32843 13.3284 3 12.5 3C11.6716 3 11 2.32843 11 1.5C11 0.671573 11.6716 0 12.5 0C13.3284 0 14 0.671573 14 1.5Z" fill="currentColor"></path><!--/$--><!--$--><path d="M25 1.5C25 2.32843 24.3284 3 23.5 3C22.6716 3 22 2.32843 22 1.5C22 0.671573 22.6716 0 23.5 0C24.3284 0 25 0.671573 25 1.5Z" fill="currentColor"></path><!--/$--><!--$--><path d="M7 1.5C7 2.32843 6.32843 3 5.5 3C4.67157 3 4 2.32843 4 1.5C4 0.671573 4.67157 0 5.5 0C6.32843 0 7 0.671573 7 1.5Z" fill="currentColor"></path><!--/$--><!--$--><path d="M18 1.5C18 2.32843 17.3284 3 16.5 3C15.6716 3 15 2.32843 15 1.5C15 0.671573 15.6716 0 16.5 0C17.3284 0 18 0.671573 18 1.5Z" fill="currentColor"></path><!--/$--><!--$--><path d="M18 5.5C18 6.32843 17.3284 7 16.5 7C15.6716 7 15 6.32843 15 5.5C15 4.67157 15.6716 4 16.5 4C17.3284 4 18 4.67157 18 5.5Z" fill="currentColor"></path><!--/$--><!--$--><path d="M29 5.5C29 6.32843 28.3284 7 27.5 7C26.6716 7 26 6.32843 26 5.5C26 4.67157 26.6716 4 27.5 4C28.3284 4 29 4.67157 29 5.5Z" fill="currentColor"></path><!--/$--><!--$--><path d="M25 5.5C25 6.32843 24.3284 7 23.5 7C22.6716 7 22 6.32843 22 5.5C22 4.67157 22.6716 4 23.5 4C24.3284 4 25 4.67157 25 5.5Z" fill="currentColor"></path><!--/$--><!--$--><path d="M3 9.5C3 10.3284 2.32843 11 1.5 11C0.671573 11 0 10.3284 0 9.5C0 8.67157 0.671573 8 1.5 8C2.32843 8 3 8.67157 3 9.5Z" fill="currentColor"></path><!--/$--><!--$--><path d="M29 9.5C29 10.3284 28.3284 11 27.5 11C26.6716 11 26 10.3284 26 9.5C26 8.67157 26.6716 8 27.5 8C28.3284 8 29 8.67157 29 9.5Z" fill="currentColor"></path><!--/$--></svg><!--/$--></div><div class="about_modal_deco_text u-text-mono">EST 2022</div><div class="about_modal_deco_text u-text-mono">BASEDÃ‚Â INÃ‚Â MELBOURNE and HANOI</div></div><div class="about_modal_video_wrap"><!--$--><video src="https://byhuy.b-cdn.net/WebM/Looping%20About%20photo.webm" loop="" muted="" playsinline=" " STYLE="" autoplay="" class="about_modal_video u-background-skeleton about-modal-video"></video><!--/$--><div class="about_modal_overlay"></div><div class="about_modal_text u-text-style-h2">listen</div><div class="about_modal_text u-text-style-h2 is-1">create</div><div class="about_modal_text u-text-style-h2 is-2">obsess</div><div class="about_modal_text u-text-style-h2 is-3">inspire</div></div></div></div><div class="about_modal_clients"><div class="about_modal_circle"><div class="g_eyebrow_circle"></div><h2 class="about_modal_heading u-text-style-h5">Clients</h2></div><div class="about_clients_collection w-dyn-list"><div role="list" class="about_clients_list w-dyn-items"><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Vinamilk</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Moc Chau Creamery</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">OH Architecture</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Supersolid Agency</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Mammoth Murals</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">SLIK Agency</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">BlueBrown Partners</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Backhouse</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">University of Sydney</h2></div></div></div></div><div class="about_modal_awards"><div class="about_modal_circle"><div class="g_eyebrow_circle"></div><h2 class="about_modal_heading u-text-style-h5">Awards</h2></div><div class="about_clients_collection w-dyn-list"><div role="list" class="about_clients_list w-dyn-items"><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Awwwards SOTD (x5)</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">FWA SOTD (x5)</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">CSSDA SOTD (x5)</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Awwwards Honorable Mention (x8)</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Awwwards Typography Honors (x2)</h2></div><div role="listitem" class="about_clients_item w-dyn-item"><h2 class="about_modal_heading u-text-style-h5">Awwwards Independent of The Year Nominee (x1)</h2></div></div></div></div><div class="about_modal_values"><div class="about_modal_circle"><div class="g_eyebrow_circle"></div><h2 class="about_modal_heading u-text-style-h5">Our principles</h2></div><div class="about_values_collection w-dyn-list"><div role="list" class="about_values_list w-dyn-items"><div role="listitem" class="about_values_item w-dyn-item"><h2 class="about_values_heading u-text-style-h5">Outcomes first, taste second</h2><p class="about_values_p u-text-style-small">Every creative decision we make is interrogated against one question: does this actually serve your growth?</p></div><div role="listitem" class="about_values_item w-dyn-item"><h2 class="about_values_heading u-text-style-h5">All in or nothing</h2><p class="about_values_p u-text-style-small">We take on fewer projects so we can give each one everything. When we commit to your brand, we&#x27;re fully present, fully invested, fully responsible for the result.</p></div><div role="listitem" class="about_values_item w-dyn-item"><h2 class="about_values_heading u-text-style-h5">Human-first, always</h2><p class="about_values_p u-text-style-small">Behind every brand is a person with a real story and real stakes. We never lose sight of that. The most powerful digital experiences are the ones that feel unmistakably human.</p></div><div role="listitem" class="about_values_item w-dyn-item"><h2 class="about_values_heading u-text-style-h5">Intention over speed</h2><p class="about_values_p u-text-style-small">Rushed work compounds into regret. We move at the pace the work demands. Every layer earns its place before we move to the next.</p></div></div></div></div></div></div></div><div class="menu_wrap"><div class="menu_contain"><!--$--><ul class="menu_contain_nav u-gap-small u-hflex-left-center"><!--$--><li data-wf--footer-link--variant="base" class="footer_nav_li"><!--$--><button id="" data-open-modal="" data-hover-highlight="link" class="footer_nav_text"><div data-hover-heading="" class="footer_nav_span u-text-style-h3">About</div><div data-footer-arrow="" class="footer_nav_span u-text-style-h3 is-arrow">Ã¢â€ â€™</div></button><!--/$--></li><!--/$--><!--$--><li data-wf--footer-link--variant="base" class="footer_nav_li"><a href="#work" data-hover-highlight="link" class="footer_nav_text w-inline-block"><div data-hover-heading="" class="footer_nav_span u-text-style-h3">Work</div><div data-footer-arrow="" class="footer_nav_span u-text-style-h3 is-arrow">Ã¢â€ â€™</div></a></li><!--/$--><!--$--><li data-wf--footer-link--variant="base" class="footer_nav_li"><a href="#process" data-hover-highlight="link" class="footer_nav_text w-inline-block"><div data-hover-heading="" class="footer_nav_span u-text-style-h3">Process</div><div data-footer-arrow="" class="footer_nav_span u-text-style-h3 is-arrow">Ã¢â€ â€™</div></a></li><!--/$--><!--$--><li data-wf--footer-link--variant="base" class="footer_nav_li"><a href="#services" data-hover-highlight="link" class="footer_nav_text w-inline-block"><div data-hover-heading="" class="footer_nav_span u-text-style-h3">Services</div><div data-footer-arrow="" class="footer_nav_span u-text-style-h3 is-arrow">Ã¢â€ â€™</div></a></li><!--/$--><!--$--><li data-wf--footer-link--variant="base" class="footer_nav_li"><a data-hover-highlight="link" class="footer_nav_text w-inline-block"><div data-hover-heading="" class="footer_nav_span u-text-style-h3">Contact</div><div data-footer-arrow="" class="footer_nav_span u-text-style-h3 is-arrow">Ã¢â€ â€™</div></a></li><!--/$--></ul><!--/$--></div><div class="menu_popup_collection w-dyn-list"><div role="list" class="menu_popup_list w-dyn-items"><div role="listitem" class="menu_popup_item w-dyn-item"><a href="#" class="menu_popup_link w-inline-block"><div class="menu_popup_cover"><img loading="lazy" src="https://cdn.prod.website-files.com/68b66e92dfdef050e1802fa7/697db3aecf1ddb096d2ec598_LinkedIn_ProfilePics_2.avif" alt="" sizes="100vw" srcset="https://cdn.prod.website-files.com/68b66e92dfdef050e1802fa7/697db3aecf1ddb096d2ec598_LinkedIn_ProfilePics_2-p-500.avif 500w, https://cdn.prod.website-files.com/68b66e92dfdef050e1802fa7/697db3aecf1ddb096d2ec598_LinkedIn_ProfilePics_2.avif 1000w" class="menu_popup_image"/></div><div class="menu_popup_content"><p class="menu_popup_p u-text-style-small">Supersolid onboards FujiFilm Australia and Hyatt Hotels as clients</p><!--$--><span class="menu_popup_date u-text-mono">January 20, 2026</span><!--/$--></div></a><!--$--><svg xmlns="http://www.w3.org/2000/svg" width="100%" viewBox="0 0 12 12" fill="none" class="g_btn_svg"><!--$--><path d="M8.90954 9.09046L9 3L2.90954 3.09046L2.90213 4.32367L6.86437 4.25391L2.55914 8.55914L3.44086 9.44086L7.74609 5.13563L7.68708 9.10862L8.90954 9.09046Z" fill="currentColor"></path><!--/$--></svg><!--/$--></div></div></div><!--$--><button data-close-modal="" id="" class="menu_overlay_close"></button><!--/$--></div><div class="g_overlay"><div class="g_grain_overlay u-grain-animate"><div class="w-embed"><style>
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
</style></div></div></div><div class="guide_wrap"><div class="w-embed"><style>
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
</style></div><div data-padding-bottom="none" data-padding-top="none" class="guide_contain u-container"><div class="guide_layout"><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div><div class="guide_column"></div></div></div></div><div class="u-position-fixed"><div class="w-embed"><style>
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

    /* Nav Theme Ã¢â‚¬â€ Light */
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

    /* Nav Theme Ã¢â‚¬â€ Dark */
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

  /* Highlight row hover Ã¢â‚¬â€ desktop only */
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
</style></div></div><div data-barba-namespace="work" data-barba="container" class="page_wrap"><main class="page_main is-light"><section data-animate="" data-theme-section="light" class="hero_work_wrap"><div class="filters-css w-embed"><style>
	/* Filter Button */
[data-btn-default] .filter-btn {
  transition: color 0.375s cubic-bezier(0.22, 1, 0.36, 1), background-color 0.375s cubic-bezier(0.22, 1, 0.36, 1);
}

.filter-btn[data-filter-status="active"] {
  background-color: #131313;
  color: #EFEEEC;
}

/* Filter List Item */
.hero_work_item[data-filter-status="active"] {
  transition: opacity 0.3s cubic-bezier(0.22, 1, 0.36, 1);
  opacity: 1 !important;
  visibility: visible !important;
  position: relative !important;
}

.hero_work_item[data-filter-status="transition-out"] {
  transition: opacity 0.25s cubic-bezier(0.22, 1, 0.36, 1);
  opacity: 0;
  visibility: visible;
}

.hero_work_item[data-filter-status="not-active"] {
  opacity: 0 !important;
  visibility: hidden !important;
  position: absolute !important;
  pointer-events: none !important;
}
.works_work_image {
  transform: none !important;
  scale: 1 !important;
  filter: none !important;
}
.works_work_overlay {
  display: none !important;
}
</style></div><div class="hero_work_header"><style>
  .hero_work_header {
    padding-bottom: 2rem;
    border-bottom: 1px solid #DCD7CC;
    color: #080807;
    justify-content: space-between;
    align-items: flex-end;
    display: flex;
  }
  .hero_work_heading.u-text-style-h1 {
    font-family: Khteka, Arial, sans-serif;
    font-size: clamp(5rem, 4.75rem + 1.25vw, 6rem);
    line-height: 0.85;
    font-weight: 500;
    letter-spacing: -0.03em;
    max-width: 24ch;
    color: #080807;
    margin: 0;
  }
  @media (max-width: 768px) {
    .hero_work_heading.u-text-style-h1 {
      font-size: clamp(2.4rem, 1.8rem + 4vw, 3.2rem);
      line-height: 1.02;
      max-width: 100%;
    }
  }
  .hero_work_index.u-text-style-h1 {
    font-family: Khteka, Arial, sans-serif;
    font-size: clamp(5rem, 4.75rem + 1.25vw, 6rem);
    line-height: 0.85;
    font-weight: 500;
    letter-spacing: -0.03em;
    color: #080807;
    white-space: nowrap;
  }

  /* White background — but not the dark CTA section */
  body, .page_wrap, .page_main {
    background-color: #ffffff !important;
  }
  .cta_home_wrap {
    background-color: inherit !important;
  }

  /* Inter font (shadcn dialog style) */
  .hero_work_heading,
  .works_home_inner_title,
  .works_home_p,
  .filter-btn,
  .services_work_span,
  .u-text-style-h1,
  .u-text-style-h5,
  .u-text-style-main,
  .u-text-style-small {
    font-family: 'Inter', sans-serif !important;
  }

  .hero_work_heading {
    letter-spacing: -0.025em !important;
    line-height: 1.2 !important;
    font-weight: 600 !important;
  }

  .works_home_inner_title {
    letter-spacing: -0.02em !important;
    line-height: 1.3 !important;
    font-weight: 600 !important;
  }

  .works_home_p {
    letter-spacing: -0.01em !important;
    line-height: 1.6 !important;
  }

  .works_work_title > .works_home_p.u-text-style-small:empty {
    display: none;
  }
</style><h1 class="hero_work_heading u-text-style-h1">Announcements and Advisories</h1></div><div data-animate="" data-filter-group="" role="group" data-filter-target-match="multi" data-filter-name-match="multi" class="hero_work_content"><div class="hero_work_left"><div class="hero_work_filters"><!--$--><ul id="" class="filter-buttons"><li data-btn-default="" class="filter_button_list"><button data-filter-target="announcements" data-filter-status="active" aria-pressed="true" aria-controls="filter-list" class="filter-btn u-text-style-main">Announcements</button></li><li data-btn-default="" class="filter_button_list"><button data-filter-target="advisories" data-filter-status="not-active" aria-pressed="false" aria-controls="filter-list" class="filter-btn u-text-style-main">Advisories</button></li></ul><!--/$--></div></div><div class="hero_work_projects"><div data-layout-status="active" data-layout-type="grid" class="hero_work_collection w-dyn-list"><div data-count-group="work" aria-live="polite" role="list" class="hero_work_list u-grid-custom w-dyn-items"><?php if ($ann_ct > 0): foreach ($announcements as $an): ?><div data-count-item="" data-filter-status="active" data-filter-name="<?php echo annf($an['category']); ?>" role="listitem" class="hero_work_item w-dyn-item"><div class="works_work_link"><?php if (!empty($an['link_url'])): ?><div class="clickable_wrap"><a href="<?php echo htmlspecialchars($an['link_url']); ?>" class="clickable_link w-inline-block"<?php if (strpos($an['link_url'],'http')===0){echo ' target="_blank" rel="noopener"';} ?>><span class="clickable_text u-sr-only"></span></a><button type="" class="clickable_btn"><span class="clickable_text u-sr-only"></span></button></div><?php endif; ?><div class="works_work_cover u-ratio-1-1"><?php if (!empty($an['photo_url'])): ?><img src="<?php echo htmlspecialchars($an['photo_url']); ?>" loading="lazy" alt="<?php echo htmlspecialchars($an['title']); ?>" class="works_work_image"/><?php else: ?><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBAAEAAAEAAAIBRA7" loading="lazy" alt="" class="works_work_image"/><?php endif; ?><div class="works_work_overlay"></div></div><div class="works_work_content"><div class="works_work_title"><h2 class="works_home_inner_title u-text-style-h5"><?php echo htmlspecialchars($an['title']); ?></h2><p class="works_home_p u-text-style-h5"><?php echo htmlspecialchars($an['content']); ?></p><?php if (!empty($an['location'])): ?><div class="services_work_collection w-dyn-list"><div role="list" class="services_work_list w-dyn-items"><div role="listitem" class="services_work_item u-text-style-small w-dyn-item"><span class="services_work_span">At <?php echo htmlspecialchars($an['location']); ?></span></div></div></div><?php endif; ?><p class="works_home_p u-text-style-small"><?php echo htmlspecialchars(annAudience($an, $publicBarangayNames)); ?></p></div></div></div></div>
<?php endforeach; else: ?><div role="listitem" class="hero_work_item w-dyn-item" style="grid-column:1/-1"><div class="works_work_link"><div class="works_work_content"><div class="works_work_title" style="width:100%"><p class="works_home_p u-text-style-h5" style="text-align:center;margin:0">No published updates right now.</p></div></div></div></div><?php endif; ?></div></div></div></div></section><div class="cta_work_wrap"><button id="to-top" style="display:none"></button><section data-theme-section="dark" class="cta_home_wrap"><div class="cta_home_contain"><div class="cta_home_inner"><div class="cta_home_header"><h2 class="cta_home_heading u-text-style-display u-sr-only">Ready to build an experience that moves people?</h2><!--$--><div class="cta_home_heading_contain"><div class="cta_home_heading u-text-style-display">Let&#x27;s build</div><div class="cta_home_heading u-text-style-display is-2">A safer</div><div class="cta_home_heading u-text-style-display is-3">Community</div><!--$--><span class="cta_heading_inner"><div class="cta_home_heading u-text-style-display is-4 is-arrow"></div><div class="cta_home_heading u-text-style-display is-4">Together</div></span><!--/$--></div><!--/$--><a data-btn-default="" data-wf--global-button-main--variant="large" class="g_btn_main w-variant-c2ee8580-9f06-5e9a-1461-dda4efd8c449 w-inline-block" href="login.php"><div class="g_btn_text_contain w-variant-c2ee8580-9f06-5e9a-1461-dda4efd8c449"><div class="g_btn_text u-text-style-small u-text-trim-off w-variant-c2ee8580-9f06-5e9a-1461-dda4efd8c449">Get Started</div></div><div class="g_btn_aside_wrap w-variant-c2ee8580-9f06-5e9a-1461-dda4efd8c449"><div class="g_btn_aside_bg w-variant-c2ee8580-9f06-5e9a-1461-dda4efd8c449"></div><!--$--><svg xmlns="http://www.w3.org/2000/svg" width="100%" viewBox="0 0 12 12" fill="none" class="g_btn_svg w-variant-c2ee8580-9f06-5e9a-1461-dda4efd8c449"><!--$--><path d="M8.90954 9.09046L9 3L2.90954 3.09046L2.90213 4.32367L6.86437 4.25391L2.55914 8.55914L3.44086 9.44086L7.74609 5.13563L7.68708 9.10862L8.90954 9.09046Z" fill="currentColor"></path><!--/$--></svg><!--/$--><!--$--><svg xmlns="http://www.w3.org/2000/svg" width="100%" viewBox="0 0 12 12" fill="none" class="g_btn_svg w-variant-c2ee8580-9f06-5e9a-1461-dda4efd8c449 is-absolute"><!--$--><path d="M8.90954 9.09046L9 3L2.90954 3.09046L2.90213 4.32367L6.86437 4.25391L2.55914 8.55914L3.44086 9.44086L7.74609 5.13563L7.68708 9.10862L8.90954 9.09046Z" fill="currentColor"></path><!--/$--></svg><!--/$--></div></a></div></div><div class="cta_home_bottom"><div data-wf--spacer--section-space="main" class="u-section-spacer w-variant-60a7ad7d-02b0-6682-95a5-2218e6fd1490"></div><div class="cta_home_proof"><div class="cta_home_awards"><!--$--><img src="DCVS AWARDS.png" alt="DCVS AWARDS" loading="lazy" class="cta_home_svgs"><!--/$--><!--$--><img src="ANIMAL WELFARE AWARDS.png" alt="ANIMAL WELFARE AWARDS" loading="lazy" class="cta_home_svgs is-cssda"><!--/$--><!--$--><img src="PUBLIC SERVICE AWARDS.png" alt="PUBLIC SERVICE AWARDS" loading="lazy" class="cta_home_svgs"><!--/$--></div><blockquote class="cta_home_testimonial_message u-text-style-h5">A modern platform that makes animal welfare services more accessible, transparent, and connected for every community.</blockquote><div class="cta_home_testimonee u-text-mono">&#8212; Dasmari&#241;as City Veterinary Services</div></div></div></div><div data-start="top bottom" data-scroll-container="" data-target-translate="150" class="cta_home_cover"><div class="cta_home_overlay"></div><img data-translate-hero="true" loading="lazy" alt="Dasmarinas City Veterinary Services" src="footer image.jpg" class="cta_home_visual"/></div></section></div><!--$--><!--/$--></main></div><script src="https://d3e54v103j8qbb.cloudfront.net/js/jquery-3.5.1.min.dc5e7f18c8.js?site=68b652bbd6c64a44c8fe3e5e" type="text/javascript" integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0=" crossorigin="anonymous"></script><script src="https://cdn.prod.website-files.com/68b652bbd6c64a44c8fe3e5e/js/webflow.a0aa6ca1.b7683852b8a60d8e.js" type="text/javascript" integrity="sha384-BrZ5RLWViy0IN9p2miOlvxmv2gP1sT/YqoMSDJznd6k1tb7t0mPZus3psGWfkx5f" crossorigin="anonymous"></script><script src="https://cdn.prod.website-files.com/gsap/3.15.0/gsap.min.js" type="text/javascript"></script><script src="https://cdn.prod.website-files.com/gsap/3.15.0/ScrollTrigger.min.js" type="text/javascript"></script><script src="https://cdn.prod.website-files.com/gsap/3.15.0/SplitText.min.js" type="text/javascript"></script><script src="https://cdn.prod.website-files.com/gsap/3.15.0/CustomEase.min.js" type="text/javascript"></script><script src="https://cdn.prod.website-files.com/gsap/3.15.0/Flip.min.js" type="text/javascript"></script><script type="text/javascript">gsap.registerPlugin(ScrollTrigger,SplitText,CustomEase,Flip);</script><script src="https://cdn.jsdelivr.net/npm/three@0.160.1/build/three.min.js"></script>
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
  script.src = jsSrc;
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function() { document.head.appendChild(script); });
  } else {
    document.head.appendChild(script);
  }
})();
</script>
<script>
(function(){
  var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-filter-target]'));
  var items = Array.prototype.slice.call(document.querySelectorAll('[data-filter-name][role="listitem"].hero_work_item'));
  function sync(){
    var active = buttons.filter(function(b){ return b.getAttribute('data-filter-status') === 'active'; });
    var target = active.length ? active[0].getAttribute('data-filter-target') : null;
    items.forEach(function(it){
      var on = !target || it.getAttribute('data-filter-name') === target;
      it.setAttribute('data-filter-status', on ? 'active' : 'not-active');
    });
  }
  buttons.forEach(function(btn){
    btn.addEventListener('click', function(){
      var name = btn.getAttribute('data-filter-target');
      buttons.forEach(function(b){
        b.setAttribute('data-filter-status', b === btn ? 'active' : 'not-active');
        b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
      });
      sync();
    });
  });
  sync();
})();
</script>
<style>
  .problems_home_item {
    padding-bottom: 2rem;
  }
  .hero_work_item .works_work_cover.u-ratio-1-1 {
    background-color: #e8e8e3 !important;
    border: 1px solid #9a9a92 !important;
  }
  .hero_work_item .works_work_image[src^="data:image/gif"] {
    opacity: 0;
  }  .cta_home_wrap .g_btn_text {
    text-shadow: 0px 1.2em 0px var(--swatch--black-400) !important;
  }  .cta_home_awards .cta_home_svgs {
    width: 19vw !important;
    min-width: 11rem !important;
    max-width: 19rem !important;
    height: auto !important;
    object-fit: contain;
  }
</style>
  </body></html><!--/$-->