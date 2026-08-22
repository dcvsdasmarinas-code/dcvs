<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Knowledge Hub — DCVS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel='stylesheet' id='tailpress-app-css' href='https://geist.studio/wp-content/themes/geist/dist/assets/app-CP3cPAnG.css?ver=0.1.555' media='all' />
  <link rel='stylesheet' id='swiper-css' href='https://cdn.jsdelivr.net/npm/swiper@12/swiper-bundle.min.css' media='all' />
  <link rel='stylesheet' id='google-fonts-css' href='https://fonts.googleapis.com/css2?family=EB+Garamond:ital,wght@0,400..800;1,400..800&#038;family=Roboto+Mono:ital,wght@0,100..700;1,100..700&#038;display=swap' media='all' />
  <style>
    /* --- DCVS Navbar --- */
    :root {
      --transition: cubic-bezier(0.25, 0.65, 0.35, 1);
    }
    .site-header {
      position: fixed; top: 0; left: 0; right: 0;
      z-index: 1000; padding: 0.8rem 0;
    }
    .navbar {
      display: flex; align-items: center; gap: 2rem;
      width: min(1200px, 92vw); margin: 0 auto;
      padding: 0.85rem 1.5rem; border-radius: 999px;
      background: #ffffff;
      border: 1px solid rgba(0, 0, 0, 0.08);
      box-shadow: 0 24px 48px rgba(0, 0, 0, 0.06);
      position: relative;
      font-family: "Plus Jakarta Sans", "Segoe UI", sans-serif !important;
      transition: background 0.3s var(--transition), box-shadow 0.3s var(--transition);
    }
    .brand-logo { display: block; object-fit: contain; }
    .navbar.nav-scrolled {
      background: #ffffff;
      border-color: rgba(0, 0, 0, 0.12);
      box-shadow: 0 26px 52px rgba(0, 0, 0, 0.1);
    }
    .nav-links {
      display: flex; align-items: center; justify-content: center;
      gap: 1.5rem; list-style: none; margin: 0; padding: 0; flex: 1;
    }
    .nav-links a {
      font-weight: 500; padding: 6px 16px; border-radius: 8px;
      color: #1a2a3a;
      transition: color 0.25s var(--transition), background 0.25s var(--transition);
    }
    .nav-links a::after { display: none; }
    .nav-links a:hover,
    .nav-links a:focus-visible { background: rgba(4, 94, 31, 0.06); color: #045e1f; }
    .nav-links a.active { background: rgba(4, 94, 31, 0.1); color: #045e1f; }
    .nav-item { position: relative; }
    .dropdown-toggle {
      cursor: pointer; display: flex; align-items: center; gap: 4px;
      padding: 6px 16px; border-radius: 8px; color: #1a2a3a;
      transition: background 0.2s, color 0.2s;
    }
    .dropdown-toggle:hover { background: rgba(4, 94, 31, 0.06); color: #045e1f; }
    .dropdown-toggle .arrow { font-size: 10px; transition: transform 0.3s var(--transition); }
    .nav-item.open .dropdown-toggle .arrow { transform: rotate(180deg); }
    .dropdown-menu {
      position: absolute; top: calc(100% + 8px); left: 0;
      background: #ffffff; border-radius: 12px; padding: 6px 0; min-width: 190px;
      box-shadow: 0 20px 48px rgba(0, 0, 0, 0.12);
      opacity: 0; visibility: hidden; transform: translateY(8px);
      transition: all 0.25s ease; z-index: 9999;
      border: 1px solid rgba(0, 0, 0, 0.06);
    }
    .nav-item:hover .dropdown-menu,
    .nav-item.open .dropdown-menu { opacity: 1; visibility: visible; transform: translateY(0); }
    .dropdown-menu a {
      display: block; padding: 10px 20px; font-size: 14px;
      font-weight: 500; color: #1a2a3a; white-space: nowrap;
    }
    .dropdown-menu a::after { display: none; }
    .dropdown-menu a:hover { background: rgba(4, 94, 31, 0.06); color: #045e1f; }
    .nav-toggle {
      display: none; flex-direction: column; gap: 5px;
      background: transparent; border: none; cursor: pointer; padding: 0;
    }
    .nav-toggle span {
      width: 22px; height: 2px; background: #1a2a3a; border-radius: 999px;
      transition: transform 0.3s var(--transition), opacity 0.3s var(--transition);
    }
    .nav-login-btn {
      font-weight:600; font-size:0.88rem; padding:8px 22px; border-radius:8px;
      background:#045e1f; color:#fff !important; white-space:nowrap;
      transition:background 0.2s; flex-shrink:0;
    }
    .nav-login-btn:hover { background:#043012; }
    @media (max-width: 1100px) {
      .navbar { border-radius: 24px; }
      .nav-links {
        position: fixed; inset: 90px 1.5rem auto 1.5rem;
        background: #ffffff; border-radius: 24px; padding: 1.8rem;
        flex-direction: column; align-items: flex-start; gap: 1rem;
        box-shadow: 0 24px 60px rgba(18, 39, 84, 0.25);
        transform: translateY(-20px); opacity: 0;
        pointer-events: none;
        transition: opacity 0.3s var(--transition), transform 0.3s var(--transition);
      }
      .nav-links.open { transform: translateY(0); opacity: 1; pointer-events: auto; }
      .nav-toggle { display: flex; }
    }

    /* --- Original Knowledge Hub styles --- */
    .stack-item img,
    .md\:hidden.block img {
      object-fit: cover !important;
      background-color: var(--page-bg);
      width: 100% !important;
      min-width: 100% !important;
    }
    .stack-item { width: 100% !important; }
  </style>
  <link rel="stylesheet" href="assets/css/responsive.css" />
</head>
<body>
    <?php $currentPage = 'knowledge'; $homePage = 'landing-page.php'; include 'includes/landing-navbar.php'; ?>

  <div id="pageOverlay"></div>

    <div id="smooth-wrapper">
        <div id="smooth-content">




            <section
                class="lg:min-h-[160vh] md:min-h-[140vh] min-h-[130svh] bg-white-1000 hero flex items-end pb-[50px] relative z-10">
                <div class="w-full sticky bottom-10 left-0" id="hero-sticky">
                    <div class="max-w-[1504px] w-full px-5 mx-auto">
                        <div class="flex items-end justify-between">
                            <div class="prose-content max-w-[858px]">
                                <h1>A Rabies-Free<br />
                                    <em>Philippines</em><br /> for healthier <em>communities.</em>
                                </h1>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="w-full max-h-dvh h-full absolute left-0 top-0 z-0 bg-layer"></div>

                <video width="100%"
                    class="absolute max-h-dvh left-0 object-cover object-center top-0 -z-10 w-full h-full" height="100%"
                    autoplay muted loop playsinline>
                    <source src="assets/videos/VIDEO BANNER.mp4" type="video/mp4">
                </video>
            </section>



            <section id="work" class="mt-[62px] md:mb-[67px] mb-20">
                <div class="max-w-[1504px] w-full px-5 mx-auto">

                    <div class="flex items-center md:pb-[140px] pb-6 justify-between">
                        <span class="tag">Rabies Awareness</span>
                    </div>

                    <div class="flex md:flex-nowrap flex-wrap gap-5">

                        <!-- Left: sticky description panel -->
                        <div class="md:w-6/12 w-full">
                            <div class="lg:max-h-[750px] md:max-h-[444px] md:h-full md:sticky top-[140px] left-0">
                                <div
                                    class="md:h-full md:max-h-[calc(100vh_-_195px)] md:flex flex-col md:justify-between md:mb-0 mb-12">

                                    <!-- Mobile: first image -->
                                    <a href="#positive-retail" class="md:hidden block mb-6">
                                        <!-- IMAGE SRC: https://geist.studio/wp-content/uploads/2026/05/PRHero_01-819x1024.png -->
                                        <img decoding="async" src="assets/images/CARD 1.jpg"
                                            class="w-full aspect-[4/5] object-cover object-top" alt="Positive Retail">
                                    </a>

                                    <!-- Desktop sticky content -->
                                    <div id="stickyContent" class="sticky-content fade-in">
                                        <h3 class="max-w-[500px]" id="stickyTitle">
                                            <p>Saving lives for a<br /><em>future without rabies.</em></p>
                                        </h3>
                                        <h6 class="my-4" id="stickySubtitle">PUBLIC HEALTH / PREVENTION</h6>
                                        <p class="body-para max-w-[604px]" id="stickyDescription">Through free
                                            vaccination programs, responsible pet ownership, and public awareness, the
                                            Philippines can prevent rabies before it spreads. Every vaccinated dog helps
                                            protect families and communities from a disease that is nearly always fatal
                                            but entirely preventable.</p>
                                    </div>

                                    <div class="flex items-center mt-4 justify-between sticky-content fade-in"
                                        id="stickyFooter">
                                        <h6 class="spacing" id="stickyNumber">(001 / 005)</h6>
                                    </div>
                                </div>
                            </div>

                            <!-- Mobile: remaining project cards -->
                            <div class="md:mb-0 mb-12 md:hidden block">
                                <a href="#club-mera">
                                    <!-- IMAGE SRC: https://geist.studio/wp-content/uploads/2026/04/CM_001-819x1024.png -->
                                    <img decoding="async" src="assets/images/CARD 2.jpg"
                                        class="block mb-6 aspect-[4/5] w-full object-cover object-top" alt="CLUBMERA">
                                </a>
                                <div>
                                    <h3 class="max-w-[500px]">
                                        <p>Protecting families with<br /><em>healthier communities.</em></p>
                                    </h3>
                                    <h6 class="my-4">COMMUNITY / SAFETY</h6>
                                    <p class="body-para max-w-[604px]">Achieving a rabies-free Philippines creates safer
                                        neighborhoods where children, families, and pets can live together with less
                                        risk of rabies transmission. Widespread vaccination strengthens community
                                        protection and promotes public safety.</p>
                                </div>
                                <div class="flex items-center mt-4 justify-between">
                                    <h6 class="spacing">(002 / 005)</h6>
                                </div>
                            </div>

                            <!-- Mobile: card 3 placeholder -->
                            <div class="md:mb-0 mb-12 md:hidden block">
                                <a href="#placeholder-3">
                                    <img decoding="async" src="assets/images/CARD 3.jfif" style="aspect-ratio: 4/5;"
                                        class="block mb-6 aspect-[4/5] w-full object-cover object-top"
                                        alt="Placeholder 3">
                                </a>
                                <div>
                                    <h3 class="max-w-[500px]">
                                        <p>Reducing healthcare costs<br /><em>through prevention.</em></p>
                                    </h3>
                                    <h6 class="my-4">ECONOMY / HEALTHCARE</h6>
                                    <p class="body-para max-w-[604px]">Preventing rabies through free vaccination is
                                        more cost-effective than treating human exposures. Nationwide vaccination
                                        programs reduce emergency treatments, hospital expenses, and the financial
                                        burden on Filipino families.</p>
                                </div>
                                <div class="flex items-center mt-4 justify-between">
                                    <h6 class="spacing">(003 / 005)</h6>
                                </div>
                            </div>

                            <!-- Mobile: card 4 placeholder -->
                            <div class="md:mb-0 mb-12 md:hidden block">
                                <a href="#placeholder-4">
                                    <img decoding="async" src="assets/images/CARD 4.jfif" style="aspect-ratio: 4/5;"
                                        class="block mb-6 aspect-[4/5] w-full object-cover object-top"
                                        alt="Placeholder 4">
                                </a>
                                <div>
                                    <h3 class="max-w-[500px]">
                                        <p>Promoting responsible<br /><em>pet ownership nationwide.</em></p>
                                    </h3>
                                    <h6 class="my-4">ANIMAL WELFARE / EDUCATION</h6>
                                    <p class="body-para max-w-[604px]">Free vaccination campaigns encourage responsible
                                        pet ownership by reminding owners to vaccinate their dogs and cats regularly.
                                        These efforts protect both animals and people while fostering healthier
                                        relationships between pets and their communities.</p>
                                </div>
                                <div class="flex items-center mt-4 justify-between">
                                    <h6 class="spacing">(004 / 005)</h6>
                                </div>
                            </div>

                            <!-- Mobile: card 5 placeholder -->
                            <div class="md:mb-0 mb-12 md:hidden block">
                                <a href="#placeholder-5">
                                    <img decoding="async" src="assets/images/CARD 5.jfif" style="aspect-ratio: 4/5;"
                                        class="block mb-6 aspect-[4/5] w-full object-cover object-top"
                                        alt="Placeholder 5">
                                </a>
                                <div>
                                    <h3 class="max-w-[500px]">
                                        <p>Towards a<br /><em>rabies-free Philippines.</em></p>
                                    </h3>
                                    <h6 class="my-4">NATIONAL GOAL / 2030</h6>
                                    <p class="body-para max-w-[604px]">The Philippines continues working toward
                                        eliminating dog-mediated human rabies through stronger vaccination programs,
                                        public awareness, and cooperation among local governments, healthcare
                                        professionals, and veterinarians. Every vaccinated pet brings the country one
                                        step closer to a safer future.</p>
                                </div>
                                <div class="flex items-center mt-4 justify-between">
                                    <h6 class="spacing">(005 / 005)</h6>
                                </div>
                            </div>
                        </div>

                        <!-- Right: stacked images (desktop only) -->
                        <div class="md:w-6/12 w-full md:block hidden">
                            <div class="max-w-[602px] ml-auto relative">
                                <a href="#positive-retail" class="stack-item overflow-hidden block" data-index="0"
                                    data-title="&lt;p&gt;Saving lives for a&lt;br /&gt;&lt;em&gt;future without rabies.&lt;/em&gt;&lt;/p&gt;"
                                    data-subtitle="PUBLIC HEALTH / PREVENTION"
                                    data-description="Through free vaccination programs, responsible pet ownership, and public awareness, the Philippines can prevent rabies before it spreads. Every vaccinated dog helps protect families and communities from a disease that is nearly always fatal but entirely preventable."
                                    data-number="(001 / 005)" data-link="#positive-retail">
                                    <!-- IMAGE SRC: https://geist.studio/wp-content/uploads/2026/05/PRHero_01-819x1024.png -->
                                    <img decoding="async" src="assets/images/CARD 1.jpg"
                                        class="w-full aspect-[4/5] object-cover object-top" alt="Positive Retail">
                                </a>
                                <div class="h-10 block w-full"></div>
                                <a href="#club-mera" class="stack-item overflow-hidden block" data-index="1"
                                    data-title="&lt;p&gt;Protecting families with&lt;br /&gt;&lt;em&gt;healthier communities.&lt;/em&gt;&lt;/p&gt;"
                                    data-subtitle="COMMUNITY / SAFETY"
                                    data-description="Achieving a rabies-free Philippines creates safer neighborhoods where children, families, and pets can live together with less risk of rabies transmission. Widespread vaccination strengthens community protection and promotes public safety."
                                    data-number="(002 / 005)" data-link="#club-mera">
                                    <!-- IMAGE SRC: https://geist.studio/wp-content/uploads/2026/04/CM_001-819x1024.png -->
                                    <img decoding="async" src="assets/images/CARD 2.jpg"
                                        class="w-full aspect-[4/5] object-cover object-top" alt="CLUBMERA">
                                </a>
                                <div class="h-10 block w-full"></div>
                                <a href="#placeholder-3" class="stack-item overflow-hidden block" data-index="2"
                                    data-title="&lt;p&gt;Reducing healthcare costs&lt;br /&gt;&lt;em&gt;through prevention.&lt;/em&gt;&lt;/p&gt;"
                                    data-subtitle="ECONOMY / HEALTHCARE"
                                    data-description="Preventing rabies through free vaccination is more cost-effective than treating human exposures. Nationwide vaccination programs reduce emergency treatments, hospital expenses, and the financial burden on Filipino families."
                                    data-number="(003 / 005)" data-link="#placeholder-3">
                                    <img decoding="async" src="assets/images/CARD 3.jfif" style="aspect-ratio: 4/5;"
                                        class="w-full aspect-[4/5] object-cover object-top" alt="Placeholder 3">
                                </a>
                                <div class="h-10 block w-full"></div>
                                <a href="#placeholder-4" class="stack-item overflow-hidden block" data-index="3"
                                    data-title="&lt;p&gt;Promoting responsible&lt;br /&gt;&lt;em&gt;pet ownership nationwide.&lt;/em&gt;&lt;/p&gt;"
                                    data-subtitle="ANIMAL WELFARE / EDUCATION"
                                    data-description="Free vaccination campaigns encourage responsible pet ownership by reminding owners to vaccinate their dogs and cats regularly. These efforts protect both animals and people while fostering healthier relationships between pets and their communities."
                                    data-number="(004 / 005)" data-link="#placeholder-4">
                                    <img decoding="async" src="assets/images/CARD 4.jfif" style="aspect-ratio: 4/5;"
                                        class="w-full aspect-[4/5] object-cover object-top" alt="Placeholder 4">
                                </a>
                                <div class="h-10 block w-full"></div>
                                <a href="#placeholder-5" class="stack-item overflow-hidden block" data-index="4"
                                    data-title="&lt;p&gt;Towards a&lt;br /&gt;&lt;em&gt;rabies-free Philippines.&lt;/em&gt;&lt;/p&gt;"
                                    data-subtitle="NATIONAL GOAL / 2030"
                                    data-description="The Philippines continues working toward eliminating dog-mediated human rabies through stronger vaccination programs, public awareness, and cooperation among local governments, healthcare professionals, and veterinarians. Every vaccinated pet brings the country one step closer to a safer future."
                                    data-number="(005 / 005)" data-link="#placeholder-5">
                                    <img decoding="async" src="assets/images/CARD 5.jfif" style="aspect-ratio: 4/5;"
                                        class="w-full aspect-[4/5] object-cover object-top" alt="Placeholder 5">
                                </a>
                            </div>
                        </div>

                    </div>
                </div>
            </section>

            <!-- Text Reveal Section -->
            <section class="md:my-[200px] my-20">
                <div class="max-w-[1504px] px-5 mx-auto">
                    <h2 class="max-w-[1365px] md:indent-[120px] indent-[60px] honeforce lg:mb-10 mb-[54px]"
                        data-colour-sweep>
                        Every vaccinated pet, informed community, and collaborative effort brings the Philippines one
                        step closer to eliminating rabies for good.
                    </h2>
                </div>
            </section>

            <!-- Spacer to allow scrolling past the text reveal so the animation completes -->
            <div style="height: 40vh;" class="w-full pointer-events-none"></div>

        </div>
        <!-- end smooth-content -->
    </div>
    <!-- end smooth-wrapper -->
    <script id="tailpress-app-js"
        src="https://geist.studio/wp-content/themes/geist/dist/assets/app-D0MPCqqS.js?ver=0.1.555"></script>
    <script id="swiper-js" src="https://cdn.jsdelivr.net/npm/swiper@12/swiper-bundle.min.js"></script>
    <script id="lenis-js" src="https://unpkg.com/lenis@1.3.21/dist/lenis.min.js"></script>
    <script id="gsap-js" src="https://cdn.jsdelivr.net/npm/gsap@3/dist/gsap.min.js"></script>
    <script id="gsap-scrolltrigger-js" src="https://cdn.jsdelivr.net/npm/gsap@3/dist/ScrollTrigger.min.js"></script>

</body>

</html>