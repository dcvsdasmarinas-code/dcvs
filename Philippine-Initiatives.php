<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Philippine Initiatives — DCVS</title>
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
        max-height: calc(100vh - 110px); overflow-y: auto;
      }
      .nav-links.open { transform: translateY(0); opacity: 1; pointer-events: auto; }
      .nav-toggle { display: flex; }
      .nav-login-btn { display: inline-block; }
    }

    /* --- Premium card styles --- */
    .premium-card { position: relative; cursor: pointer; outline: none; }
    .premium-card-img { transition: filter 300ms ease-in-out; }
    .premium-card:hover .premium-card-img,
    .premium-card:focus .premium-card-img { filter: blur(3px); }
    .premium-card-overlay {
      position: absolute; inset: 0;
      background-color: rgba(0, 0, 0, 0.3);
      opacity: 0; transition: opacity 300ms ease-in-out;
      display: flex; align-items: center; justify-content: center;
    }
    .premium-card:hover .premium-card-overlay,
    .premium-card:focus .premium-card-overlay { opacity: 1; }
    .premium-card-text {
      color: white; font-size: 1rem; font-weight: 500;
      display: flex; align-items: center; gap: 0.5rem;
      transform: translateY(8px); transition: transform 300ms ease-in-out, opacity 300ms ease-in-out;
      opacity: 0;
    }
    .premium-card:hover .premium-card-text,
    .premium-card:focus .premium-card-text { transform: translateY(0); opacity: 1; }
    .premium-card-arrow { transition: transform 300ms ease-in-out; }
    .premium-card:hover .premium-card-arrow,
    .premium-card:focus .premium-card-arrow { transform: translateX(4px); }
    .premium-card:hover h6,
    .premium-card:focus h6 { color: #d62828; }
  </style>
  <link rel="stylesheet" href="assets/css/responsive.css" />
</head>
<body>

  <?php $currentPage = 'philippine-initiatives'; $homePage = 'landing-page.php'; include 'includes/landing-navbar.php'; ?>

  <div id="pageOverlay"></div>

    <div id="smooth-wrapper">
        <div id="smooth-content">



            <section class="md:pt-[110px] pt-[92px]">
                <div class="max-w-[1504px] w-full px-5 mx-auto">
                    <div class="flex md:items-end flex-wrap md:mb-[30px] gap-[22px] justify-between">
                        <h1 class="md:max-w-[686px] max-w-[242px]">National <em>efforts</em> saving <em>lives</em> every
                            year.</h1>
                        <ul class="tag md:w-auto w-full flex items-center">
                            (
                            <li><a href="#" class="tag" id="reelTab">reel /</a></li>
                            <li><a href="#" class="tag text-gray-1000! ml-2" id="gridTab">grid</a></li>
                            )
                        </ul>
                    </div>
                </div>
            </section>

            <!-- Reel view (Swiper) -->
            <div id="reelView">
                <section class="overflow-hidden md:mb-[244px] pb-0 md:pt-[60px] pt-8">
                    <div class="max-w-[1504px] overflow-visible w-full px-5 mx-auto">
                        <div class="slider-section ">
                            <div class="swiper mySwiper">
                                <div class="swiper-wrapper">
                                                                                                            <div class="swiper-slide">
                                        <a href="https://www.youtube.com/watch?v=nhuLIlyO50k" target="_blank" class="premium-card flex md:flex-col flex-col-reverse gap-y-3 block">
                                            <h6 class="md:text-base! text-sm! pr-2 transition-colors duration-300">Mass Dog Vaccination</h6>
                                            <div class="relative overflow-hidden w-full aspect-[4/5]">
                                                <img class="aspect-[4/5] object-cover object-center w-full premium-card-img" src="assets/images/WORK 1.jpg" alt="Mass Dog Vaccination">
                                                <div class="premium-card-overlay">
                                                    <span class="premium-card-text">
                                                        Learn More <span class="premium-card-arrow">→</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                                                                                            <div class="swiper-slide">
                                        <a href="https://bounty.com.ph/2023/03/22/bounty-anti-rabies-campaign-raising-awareness-and-saving-lives-in-our-community/" target="_blank" class="premium-card flex md:flex-col flex-col-reverse gap-y-3 block">
                                            <h6 class="md:text-base! text-sm! pr-2 transition-colors duration-300">Public Awareness Campaigns</h6>
                                            <div class="relative overflow-hidden w-full aspect-[4/5]">
                                                <img class="aspect-[4/5] object-cover object-center w-full premium-card-img" src="assets/images/WORK 2.webp" alt="Public Awareness Campaigns">
                                                <div class="premium-card-overlay">
                                                    <span class="premium-card-text">
                                                        Learn More <span class="premium-card-arrow">→</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                                                                                            <div class="swiper-slide">
                                        <a href="https://davaocity.gov.ph/animal-welfare/davao-city-animal-bite-treatment-center-reminds-public-to-take-rabies-seriously/" target="_blank" class="premium-card flex md:flex-col flex-col-reverse gap-y-3 block">
                                            <h6 class="md:text-base! text-sm! pr-2 transition-colors duration-300">Animal Bite Treatment Centers</h6>
                                            <div class="relative overflow-hidden w-full aspect-[4/5]">
                                                <img class="aspect-[4/5] object-cover object-center w-full premium-card-img" src="assets/images/WORK 3.jpg" alt="Animal Bite Treatment Centers">
                                                <div class="premium-card-overlay">
                                                    <span class="premium-card-text">
                                                        Learn More <span class="premium-card-arrow">→</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                                                                                            <div class="swiper-slide">
                                        <a href="https://mirror.pia.gov.ph/features/2024/03/07/responsible-pet-ownership-key-in-campaign-to-curb-rise-in-rabies-cases" target="_blank" class="premium-card flex md:flex-col flex-col-reverse gap-y-3 block">
                                            <h6 class="md:text-base! text-sm! pr-2 transition-colors duration-300">Responsible Pet Ownership</h6>
                                            <div class="relative overflow-hidden w-full aspect-[4/5]">
                                                <img class="aspect-[4/5] object-cover object-center w-full premium-card-img" src="assets/images/WORK 4.jpeg" alt="Responsible Pet Ownership">
                                                <div class="premium-card-overlay">
                                                    <span class="premium-card-text">
                                                        Learn More <span class="premium-card-arrow">→</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                                                                                            <div class="swiper-slide">
                                        <a href="https://resilience.up.edu.ph/ai-powered-one-health-approach-to-rabies-elimination-upri-rcw-director-takes-the-stage-at-rabies-summit-2026/" target="_blank" class="premium-card flex md:flex-col flex-col-reverse gap-y-3 block">
                                            <h6 class="md:text-base! text-sm! pr-2 transition-colors duration-300">National Rabies Elimination Program</h6>
                                            <div class="relative overflow-hidden w-full aspect-[4/5]">
                                                <img class="aspect-[4/5] object-cover object-center w-full premium-card-img" src="assets/images/WORK 5.jpeg" alt="National Rabies Elimination Program">
                                                <div class="premium-card-overlay">
                                                    <span class="premium-card-text">
                                                        Learn More <span class="premium-card-arrow">→</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                                                                                            <div class="swiper-slide">
                                        <a href="https://www.army.mil/article/239426/world_rabies_day_how_military_communities_can_help_end_rabies" target="_blank" class="premium-card flex md:flex-col flex-col-reverse gap-y-3 block">
                                            <h6 class="md:text-base! text-sm! pr-2 transition-colors duration-300">World Rabies Day</h6>
                                            <div class="relative overflow-hidden w-full aspect-[4/5]">
                                                <img class="aspect-[4/5] object-cover object-center w-full premium-card-img" src="assets/images/WORK 6.jfif" alt="World Rabies Day">
                                                <div class="premium-card-overlay">
                                                    <span class="premium-card-text">
                                                        Learn More <span class="premium-card-arrow">→</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                                                                                            <div class="swiper-slide">
                                        <a href="https://asianews.network/with-over-200-average-deaths-yearly-philippines-far-from-rabies-free-status/" target="_blank" class="premium-card flex md:flex-col flex-col-reverse gap-y-3 block">
                                            <h6 class="md:text-base! text-sm! pr-2 transition-colors duration-300">One Health Approach</h6>
                                            <div class="relative overflow-hidden w-full aspect-[4/5]">
                                                <img class="aspect-[4/5] object-cover object-center w-full premium-card-img" src="assets/images/WORK 7.webp" alt="One Health Approach">
                                                <div class="premium-card-overlay">
                                                    <span class="premium-card-text">
                                                        Learn More <span class="premium-card-arrow">→</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                                                                                            <div class="swiper-slide">
                                        <a href="https://leadsanimalhealth.com/anti-rabies-drive/" target="_blank" class="premium-card flex md:flex-col flex-col-reverse gap-y-3 block">
                                            <h6 class="md:text-base! text-sm! pr-2 transition-colors duration-300">Community Vaccination Drives</h6>
                                            <div class="relative overflow-hidden w-full aspect-[4/5]">
                                                <img class="aspect-[4/5] object-cover object-center w-full premium-card-img" src="assets/images/WORK 8.jpg" alt="Community Vaccination Drives">
                                                <div class="premium-card-overlay">
                                                    <span class="premium-card-text">
                                                        Learn More <span class="premium-card-arrow">→</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                                                                                            <div class="swiper-slide">
                                        <a href="https://www.pna.gov.ph/articles/1170481" target="_blank" class="premium-card flex md:flex-col flex-col-reverse gap-y-3 block">
                                            <h6 class="md:text-base! text-sm! pr-2 transition-colors duration-300">Success Stories</h6>
                                            <div class="relative overflow-hidden w-full aspect-[4/5]">
                                                <img class="aspect-[4/5] object-cover w-full premium-card-img" src="assets/images/WORK 9.jfif" alt="Success Stories" style="object-position: 2% center;">
                                                <div class="premium-card-overlay">
                                                    <span class="premium-card-text">
                                                        Learn More <span class="premium-card-arrow">→</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                                                                                            <div class="swiper-slide">
                                        <a href="https://www.who.int/publications/i/item/9789241513838" target="_blank" class="premium-card flex md:flex-col flex-col-reverse gap-y-3 block">
                                            <h6 class="md:text-base! text-sm! pr-2 transition-colors duration-300">Rabies-Free by 2030</h6>
                                            <div class="relative overflow-hidden w-full aspect-[4/5]">
                                                <img class="aspect-[4/5] object-cover object-center w-full premium-card-img" src="assets/images/WORK 10.jfif" alt="Rabies-Free by 2030">
                                                <div class="premium-card-overlay">
                                                    <span class="premium-card-text">
                                                        Learn More <span class="premium-card-arrow">→</span>
                                                    </span>
                                                </div>
                                            </div>
                                        </a>
                                    </div>
                                </div>
                            </div>



                            <div class="mt-11 md:hidden block max-w-[233px] mx-auto">
                                <div
                                    class="swiper-pagination h-[3px] static! w-full swiper-pagination-progressbar swiper-pagination-horizontal">
                                    <span class="swiper-pagination-progressbar-fill"
                                        style="transform: translate3d(0px, 0px, 0px) scaleX(0.125) scaleY(1); transition-duration: 300ms;"></span>
                                </div>
                            </div>




                        </div>
                    </div>
                </section>
            </div>

            <!-- Grid / List view -->
            <div id="gridView" class="hidden pt-8 md:pt-0">
                <section class="md:pb-[260px] pb-0">
                    <div class="max-w-[1504px] w-full px-5 mx-auto md:pt-[60px] pt-0">
                        <div class="flex items-start md:flex-nowrap flex-wrap gap-10">

                            <!-- Sticky image (desktop) -->
                            <div class="md:w-4/12 w-full md:block hidden sticky top-[180px] left-0">
                                <div class="max-w-[360px]">
                                    <!-- IMAGE SRC: WORK 1.jpg -->
                                    <img id="mainImage" src="assets/images/WORK 1.jpg" alt="" class="object-cover object-center"
                                        style="opacity: 1;">
                                </div>
                            </div>

                            <!-- Hover list -->
                            <div class="md:w-8/12 w-full">
                                <div class="max-w-[972px] w-full ml-auto">
                                    <!-- IMAGE SRC: WORK 1.jpg -->
                                    <a href="#"
                                        class="work-hover-item item md:pb-5 pb-2.5 border-b -mt-[1px] border-black-1000 flex items-center justify-between cursor-pointer opacity-100"
                                        data-img="assets/images/WORK 1.jpg">
                                        <h4>Mass Dog Vaccination</h4>
                                        <img src="https://geist.studio/wp-content/themes/geist/assets/images/star-icon.svg"
                                            class="star md:block hidden opacity-100" alt="">
                                    </a>
                                    <!-- IMAGE SRC: https://geist.studio/wp-content/uploads/2026/04/15-819x1024.png -->
                                    <a href="#"
                                        class="work-hover-item item border-b border-t -mt-[1px] border-black-1000 md:py-5 py-2.5 flex items-center justify-between cursor-pointer opacity-20"
                                        data-img="https://geist.studio/wp-content/uploads/2026/04/15-819x1024.png">
                                        <h4>Public Awareness Campaigns</h4>
                                        <img src="assets/images/WORK 2.webp" class="star md:block hidden opacity-0" alt="">
                                    </a>
                                    <!-- IMAGE SRC: https://geist.studio/wp-content/uploads/2026/04/CM_001-819x1024.png -->
                                    <a href="#"
                                        class="work-hover-item item border-b border-t -mt-[1px] border-black-1000 md:py-5 py-2.5 flex items-center justify-between cursor-pointer opacity-20"
                                        data-img="https://geist.studio/wp-content/uploads/2026/04/CM_001-819x1024.png">
                                        <h4>Animal Bite Treatment Centers</h4>
                                        <img src="assets/images/WORK 3.jpg" class="star md:block hidden opacity-0" alt="">
                                    </a>
                                    <!-- IMAGE SRC: https://geist.studio/wp-content/uploads/2026/04/Project-Image-Container2x-821x1024.png -->
                                    <a href="#"
                                        class="work-hover-item item border-b border-t -mt-[1px] border-black-1000 md:py-5 py-2.5 flex items-center justify-between cursor-pointer opacity-20"
                                        data-img="https://geist.studio/wp-content/uploads/2026/04/Project-Image-Container2x-821x1024.png">
                                        <h4>Responsible Pet Ownership</h4>
                                        <img src="assets/images/WORK 4.jpeg" class="star md:block hidden opacity-0" alt="">
                                    </a>
                                    <!-- IMAGE SRC: https://geist.studio/wp-content/uploads/2026/05/HeroProject-Image-821x1024.png -->
                                    <a href="#"
                                        class="work-hover-item item border-b border-t -mt-[1px] border-black-1000 md:py-5 py-2.5 flex items-center justify-between cursor-pointer opacity-20"
                                        data-img="https://geist.studio/wp-content/uploads/2026/05/HeroProject-Image-821x1024.png">
                                        <h4>National Rabies Elimination Program</h4>
                                        <img src="assets/images/WORK 5.jpeg" class="star md:block hidden opacity-0" alt="">
                                    </a>
                                    <!-- IMAGE SRC: https://geist.studio/wp-content/uploads/2026/05/Post-4-819x1024.png -->
                                    <a href="#"
                                        class="work-hover-item item border-b border-t -mt-[1px] border-black-1000 md:py-5 py-2.5 flex items-center justify-between cursor-pointer opacity-20"
                                        data-img="https://geist.studio/wp-content/uploads/2026/05/Post-4-819x1024.png">
                                        <h4>World Rabies Day</h4>
                                        <img src="assets/images/WORK 6.jfif" class="star md:block hidden opacity-0" alt="">
                                    </a>
                                    <!-- IMAGE SRC: https://geist.studio/wp-content/uploads/2026/05/Project-Image3-821x1024.png -->
                                    <a href="#"
                                        class="work-hover-item item border-b border-t -mt-[1px] border-black-1000 md:py-5 py-2.5 flex items-center justify-between cursor-pointer opacity-20"
                                        data-img="https://geist.studio/wp-content/uploads/2026/05/Project-Image3-821x1024.png">
                                        <h4>One Health Approach</h4>
                                        <img src="assets/images/WORK 7.webp" class="star md:block hidden opacity-0" alt="">
                                    </a>
                                    <!-- IMAGE SRC: https://geist.studio/wp-content/uploads/2026/05/Community Vaccination Drives_001-821x1024.png -->
                                    <a href="#"
                                        class="work-hover-item item border-b border-t -mt-[1px] border-black-1000 md:py-5 py-2.5 flex items-center justify-between cursor-pointer opacity-20"
                                        data-img="https://geist.studio/wp-content/uploads/2026/05/Community Vaccination Drives_001-821x1024.png">
                                        <h4>Community Vaccination Drives</h4>
                                        <img src="assets/images/WORK 8.jpg" class="star md:block hidden opacity-0" alt="">
                                    </a>
                                    <!-- IMAGE SRC: https://geist.studio/wp-content/uploads/2026/05/PPC_0012-821x1024.png -->
                                    <a href="#"
                                        class="work-hover-item item border-b border-t -mt-[1px] border-black-1000 md:py-5 py-2.5 flex items-center justify-between cursor-pointer opacity-20"
                                        data-img="https://geist.studio/wp-content/uploads/2026/05/PPC_0012-821x1024.png">
                                        <h4>Success Stories</h4>
                                        <img src="assets/images/WORK 9.jfif" class="star md:block hidden opacity-0" alt="">
                                    </a>
                                    <!-- IMAGE SRC: https://geist.studio/wp-content/uploads/2026/05/Rabies-Free by 2030_001-821x1024.png -->
                                    <a href="#"
                                        class="work-hover-item item md:pt-5 pt-2.5 border-t -mt-[1px] border-black-1000 flex items-center justify-between cursor-pointer opacity-20"
                                        data-img="https://geist.studio/wp-content/uploads/2026/05/Rabies-Free by 2030_001-821x1024.png">
                                        <h4>Rabies-Free by 2030</h4>
                                        <img src="assets/images/WORK 10.jfif" class="star md:block hidden opacity-0" alt="">
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>



        </div>
        <!-- end smooth-content -->
    </div>
    <!-- end smooth-wrapper -->

    <!-- Mobile Menu (outside smooth-wrapper so Lenis doesn't affect it) -->
    <div id="mobileMenu"
        class="mobile-menu fixed top-0 flex flex-col justify-between z-40 left-0 w-full bg-red-1100 px-5">
        <div class="flex py-[25px] items-center justify-between">
            <a href="#" id="closeMenu">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M1 1L17 17" stroke="#FF1400" stroke-width="2" stroke-linecap="round" />
                    <path d="M17 1L1 17" stroke="#FF1400" stroke-width="2" stroke-linecap="round" />
                </svg>
            </a>
        </div>
        <ul class="space-y-5">
            <li>
                <a href="work.html" class="mobile-links">Work</a>
            </li>
            <li>
                <a href="#" class="mobile-links">About</a>
            </li>
            <li>
                <a href="#" class="mobile-links">News</a>
            </li>
            <li>
                <a href="#" class="mobile-links">Let&#039;s chat</a>
            </li>
        </ul>
        <div>
            <div class="text-end mb-7">
                <span class="block text-xs font-roboto font-bold leading-[140%] text-red-1000 uppercase">social -
                    @HeyGEIST</span>
                <span class="block text-xs font-roboto font-bold leading-[140%] text-red-1000 uppercase">Email - <a
                        href="/cdn-cgi/l/email-protection" class="__cf_email__"
                        data-cfemail="5038353c3c3f1037353923247e23242534393f">[email&#160;protected]</a></span>
            </div>
            <img src="https://geist.studio/wp-content/themes/geist/assets/images/tiger-red.svg" alt="">
        </div>
    </div>

    <script id="tailpress-app-js"
        src="https://geist.studio/wp-content/themes/geist/dist/assets/app-D0MPCqqS.js?ver=0.1.555"></script>
    <script id="swiper-js" src="https://cdn.jsdelivr.net/npm/swiper@12/swiper-bundle.min.js"></script>
    <script id="lenis-js" src="https://unpkg.com/lenis@1.3.21/dist/lenis.min.js"></script>
    <script id="gsap-js" src="https://cdn.jsdelivr.net/npm/gsap@3/dist/gsap.min.js"></script>
    <script id="gsap-scrolltrigger-js" src="https://cdn.jsdelivr.net/npm/gsap@3/dist/ScrollTrigger.min.js"></script>

    </body>

</html>
