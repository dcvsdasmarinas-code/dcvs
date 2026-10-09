<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Adopt a rescue dog from Animal Kingdom Foundation. Give our lovely rescues the life they deserve." />
  <title>Adopt a Rescue | Dasmariñas City Veterinary Services</title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />

  <style>
@font-face{font-family:Khteka;src:url(https://cdn.prod.website-files.com/68b652bbd6c64a44c8fe3e5e/6968b3919a24a99e002f91dc_KHTeka-Medium.woff2)format("woff2");font-weight:500;font-style:normal;font-display:swap}
*,
*::before,
*::after {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

:root {
  --akf-red: #045e1f;
  --akf-gold: #FF8C00;
  --akf-dark: #212121;
  --akf-gray: #f5f5f5;
  --akf-white: #ffffff;
  --akf-border: #e5e5e5;
  --akf-muted: #737373;
  --radius: 0.25rem;
}

html {
  scroll-behavior: smooth;
}

body {
  font-family: 'Inter','Segoe UI',sans-serif;
  color: var(--akf-dark);
  background: #ffffff;
  -webkit-font-smoothing: antialiased;
  line-height: 1.6;
}

html, body {
  overflow-x: hidden;
  overflow-x: clip;
}

h1, h2, h3, h4, h5, h6 {
  font-family: 'Inter',sans-serif;
}

a {
  text-decoration: none;
  color: inherit;
}

img {
  display: block;
  max-width: 100%;
}

/* ---------- Utility ---------- */
.container {
  width: 100%;
  max-width: 1400px;
  margin: 0 auto;
  padding: 0 2rem;
}
.site-header {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 1000;
  padding: 0.8rem 0;
}

.navbar {
  display: flex;
  align-items: center;
  gap: 2rem;
  font-family: 'Inter',"Segoe UI",sans-serif !important;
  width: min(1200px, 92vw);
  margin: 0 auto;
  padding: 0.85rem 1.5rem;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.82);
  -webkit-backdrop-filter: blur(24px) saturate(1.8);
  backdrop-filter: blur(24px) saturate(1.8);
  border: 1px solid rgba(255, 255, 255, 0.65);
  box-shadow: 0 24px 48px rgba(0, 0, 0, 0.12);
  position: relative;
  transition: background 0.3s ease, box-shadow 0.3s ease, border 0.3s ease, transform 0.3s ease;
}

.brand-logo {
  display: block;
  object-fit: contain;
}

.navbar::before,
.navbar::after { display: none; }

.navbar.nav-scrolled {
  background: rgba(255, 255, 255, 0.88);
  border-color: rgba(0, 0, 0, 0.12);
  box-shadow: 0 26px 52px rgba(0, 0, 0, 0.1);
}

.nav-links {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1.5rem;
  list-style: none;
  margin: 0;
  padding: 0;
  flex: 1;
}

.nav-links a {
  font-weight: 500;
  padding: 6px 16px;
  border-radius: 8px;
  color: #1a2a3a;
  transition: color 0.25s ease, background 0.25s ease;
}

.nav-links a::after { display: none; }

.nav-links a:hover,
.nav-links a:focus-visible {
  background: rgba(4, 94, 31, 0.06);
  color: #045e1f;
}
.nav-links a.active {
  background: rgba(4, 94, 31, 0.1);
  color: #045e1f;
}
.nav-item{position:relative;}
.dropdown-toggle{cursor:pointer;display:flex;align-items:center;gap:4px;padding:6px 16px;border-radius:8px;color:#1a2a3a;transition:background 0.2s,color 0.2s;}
.dropdown-toggle:hover{background:rgba(4,94,31,0.06);color:#045e1f;}
.dropdown-toggle .arrow{font-size:10px;transition:transform 0.3s ease;}
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

.nav-toggle {
  display: none;
  flex-direction: column;
  gap: 5px;
  background: transparent;
  border: none;
  cursor: pointer;
  padding: 0;
}

.nav-toggle span {
  width: 22px;
  height: 2px;
  background: #1a2a3a;
  border-radius: 999px;
}
.nav-login-btn { font-weight: 600; font-size: 0.88rem; padding: 8px 22px; border-radius: 8px; background: #045e1f; color: #fff !important; white-space: nowrap; transition: background 0.2s; flex-shrink: 0; }
.nav-login-btn:hover { background: #043012; }

@media (max-width: 1100px) {
  .navbar { border-radius: 24px; }
  .nav-toggle { display: flex; }

  .nav-links {
    position: fixed;
    inset: 90px 1.5rem auto 1.5rem;
    background: #ffffff;
    border-radius: 24px;
    padding: 1.8rem;
    flex-direction: column;
    align-items: flex-start;
    justify-content: flex-start;
    gap: 1rem;
    box-shadow: 0 24px 60px rgba(18, 39, 84, 0.25);
    transform: translateY(-20px);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s ease, transform 0.3s ease;
    max-height: calc(100vh - 110px);
    overflow-y: auto;
  }

  .nav-links.open {
    transform: translateY(0);
    opacity: 1;
    pointer-events: auto;
  }

  .nav-login-btn { display: inline-block; }
}

/* ---------- New Adoption Hero ---------- */
.adoption-hero-section{background:#fff;padding:140px 0 60px;}
.adoption-hero-section .hero-grid{display:grid;grid-template-columns:1.05fr 1fr;gap:40px;align-items:start;}
@media(max-width:860px){.adoption-hero-section .hero-grid{grid-template-columns:1fr;}}
.adoption-hero-section .left-col img{width:100%;aspect-ratio:350/300;object-fit:cover;border-radius:16px;display:block;background:#e4e1db;}
.adoption-hero-section blockquote{margin:22px 0 0;padding-left:18px;border-left:3px solid #cfcac0;font-style:italic;font-size:17px;color:#5a6270;line-height:1.5;}
.adoption-hero-section .right-col{display:flex;flex-direction:column;}
.adoption-hero-section .top-photos{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:32px;}
.adoption-hero-section .top-photos img{width:100%;aspect-ratio:1/1.2;object-fit:cover;border-radius:14px;display:block;background:#e4e1db;}
.adoption-hero-section h1{font-weight:800;font-size:3rem;color:var(--akf-red);margin:0 0 1.5rem;font-family:'Inter',sans-serif;line-height:1.15;letter-spacing:-0.025em;}
.adoption-hero-section .desc{font-size:1.125rem;color:var(--akf-dark);max-width:480px;margin:0 0 26px;line-height:1.7;}
.adoption-hero-section .cta-row{display:flex;gap:14px;flex-wrap:wrap;}
.adoption-hero-section .cta-btn{display:inline-flex;align-items:center;gap:10px;padding:0 24px;height:44px;border-radius:6px;border:1px solid transparent;cursor:pointer;font-family:'Inter',sans-serif;font-weight:500;font-size:16px;color:#fafafa;background:#18181b;text-decoration:none;box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);transition:background .15s;}
.adoption-hero-section .cta-btn:hover{background:#27272a;}
.adoption-hero-section .cta-btn .icon{width:24px;height:24px;border-radius:50%;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
@media(max-width:520px){.adoption-hero-section h1{font-size:38px;}}

/* ---------- Meet The Hearts ---------- */
.adoption-hearts-section{background:#fff;padding:60px 0;}
.adoption-hearts-section .about-grid{display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:center;}
@media(max-width:860px){.adoption-hearts-section .about-grid{grid-template-columns:1fr;}}
.adoption-hearts-section .about-col h1{font-size:3rem;font-weight:800;color:var(--akf-red);margin:0 0 1.5rem;font-family:'Inter',sans-serif;line-height:1.15;letter-spacing:-0.025em;}
.adoption-hearts-section .about-col .desc{font-size:1.125rem;color:var(--akf-dark);max-width:460px;margin:0 0 24px;line-height:1.7;}
.adoption-hearts-section .about-col blockquote{margin:0 0 30px;padding-left:18px;border-left:3px solid #cfcac0;font-style:italic;font-size:1.125rem;color:var(--akf-dark);line-height:1.7;}
.adoption-hearts-section .about-photos{display:grid;grid-template-columns:1.3fr 1fr;gap:24px;align-items:end;}
.adoption-hearts-section .about-photos img{width:100%;object-fit:cover;border-radius:16px;display:block;background:#e4e1db;}
.adoption-hearts-section .about-photos .tall{aspect-ratio:1/1.2;}
.adoption-hearts-section .about-photos .short{aspect-ratio:1/0.9;margin-bottom:40px;}
@media(max-width:860px){.adoption-hearts-section .about-photos .short{margin-bottom:0;}}

/* ---------- How to Adopt ---------- */
.how-to-adopt {
  padding: 5rem 0 7rem;
  background: var(--akf-white);
}

.how-to-adopt h2 {
  font-weight: 800;
  font-size: 3rem;
  color: var(--akf-red);
  text-align: center;
  margin-bottom: 4rem;
}

.steps-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 2rem;
}

.step {
  text-align: center;
  padding: 0 1rem;
}

.step h3 {
  font-weight: 800;
  font-size: 1.875rem;
  color: var(--akf-red);
  margin-bottom: 1rem;
}

.step p {
  line-height: 1.7;
  text-align: justify;
}

/* ---------- Adopt Section ---------- */
.adopt-section {
  padding: 4rem 0 6rem;
  background: var(--akf-white);
}

.adopt-carousel-wrapper {
  position: relative;
  padding: 0 50px;
}

.adopt-carousel {
  overflow: hidden;
  position: relative;
}

.adopt-slide {
  display: none;
  opacity: 0;
  transition: opacity 0.6s ease-in-out;
}

.adopt-slide.active {
  display: block;
  opacity: 1;
}

.adopt-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 3rem;
  align-items: start;
  min-height: 400px;
}

.adopt-label {
  display: block;
  font-family: 'Inter', sans-serif;
  font-weight: 800;
  font-size: 1.25rem;
  color: var(--akf-red);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 0.5rem;
}

.adopt-grid > div:first-child {
  display: flex;
  flex-direction: column;
  justify-content: center;
  min-height: 400px;
}

.adopt-grid h2 {
  font-weight: 800;
  font-size: 3rem;
  line-height: 1.15;
  margin-bottom: 1.5rem;
  color: var(--akf-dark);
}

.adopt-grid p {
  line-height: 1.7;
  text-align: justify;
}

.adopt-img {
  border-radius: var(--radius);
  overflow: hidden;
  height: 400px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.adopt-img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

/* Carousel Navigation Buttons */
.carousel-btn {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  background: var(--akf-red);
  color: var(--akf-white);
  border: none;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10;
  transition: background 0.3s;
}

.carousel-btn:hover {
  background: #c40f1f;
}

.carousel-btn svg {
  width: 24px;
  height: 24px;
}

.carousel-prev {
  left: -30px;
}

.carousel-next {
  right: -30px;
}

/* Carousel Indicators */
.carousel-indicators {
  display: flex;
  justify-content: center;
  gap: 0.75rem;
  margin-top: 2rem;
}

.indicator {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: #d5d5d5;
  border: none;
  cursor: pointer;
  transition: background 0.3s;
}

.indicator.active {
  background: var(--akf-red);
}

.indicator:hover {
  background: #999;
}

/* ---------- Message Section ---------- */
.message-section {
  padding: 4rem 0 6rem;
  background: var(--akf-white);
}

.message-section h2 {
  font-weight: 800;
  font-size: 3rem;
  color: var(--akf-red);
  text-align: center;
  margin-bottom: 3rem;
}

.message-inner {
  max-width: 768px;
  margin: 0 auto;
}

.message-inner img {
  width: 100%;
  max-width: 560px;
  margin: 0 auto 2rem;
  border-radius: var(--radius);
  object-fit: cover;
}

.message-inner p {
  text-align: center;
  font-size: 1.125rem;
  line-height: 1.7;
  max-width: 640px;
  margin: 0 auto;
}

/* ---------- Reasons to Adopt ---------- */
.reasons-to-adopt-section {
  padding: 4rem 0 6rem;
  background: var(--akf-white);
}

.reasons-title {
  font-weight: 800;
  font-size: 3rem;
  color: var(--akf-red);
  text-align: center;
  margin-bottom: 3rem;
  line-height: 1.2;
}

.reasons-content {
  max-width: 900px;
  margin: 0 auto;
  text-align: center;
}

.reasons-text {
  font-size: 1.25rem;
  line-height: 1.8;
  color: var(--akf-dark);
  margin-bottom: 2rem;
  text-align: center;
}

.reasons-image {
  margin: 3rem auto;
  border-radius: var(--radius);
  overflow: hidden;
  box-shadow: 0 20px 40px rgba(4, 94, 31, 0.15);
  max-width: 600px;
}

.reasons-image img {
  width: 100%;
  height: auto;
  object-fit: cover;
  transition: transform 0.5s ease;
}

.reasons-image:hover img {
  transform: scale(1.02);
}

/* ---------- Gallery ---------- */
.gallery-section {
  padding: 4rem 0 0;
  background: var(--akf-white);
}

.gallery-section h2 {
  font-weight: 900;
  font-size: 3rem;
  text-transform: uppercase;
  line-height: 1.15;
  margin-bottom: 3rem;
  color: var(--akf-dark);
}

.gallery-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 0;
}

.gallery-item {
  aspect-ratio: 1;
  overflow: hidden;
}

.gallery-item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.5s ease;
}

.gallery-item:hover img {
  transform: scale(1.05);
}

/* ---------- Meet Our Rescues ---------- */
.meet-rescues-section {
  padding: 4rem 0 6rem;
  background: var(--akf-white);
}

.meet-rescues-header {
  text-align: center;
  margin-bottom: 3rem;
}

.meet-rescues-header h2 {
  font-weight: 600;
  font-size: 1.5rem;
  color: var(--akf-dark);
  margin-bottom: 1rem;
  line-height: 1.4;
  max-width: 800px;
  margin-left: auto;
  margin-right: auto;
}

.meet-rescues-footer {
  text-align: center;
  margin-top: 2rem;
}

.meet-rescues-link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  height: 44px;
  padding: 0 36px;
  border-radius: 6px;
  background: #18181b;
  color: #fafafa;
  text-decoration: none;
  font-family: 'Inter',sans-serif;
  font-size: 16px;
  font-weight: 500;
  line-height: 1;
  white-space: nowrap;
  cursor: pointer;
  border: 1px solid transparent;
  box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05);
  transition: background-color 0.15s ease;
}

.meet-rescues-link:hover {
  background: #27272a;
  transform: none;
}

.meet-rescues-link h3 {
  font-family: 'Inter',sans-serif;
  font-weight: 500;
  font-size: 16px;
  color: #fafafa;
  margin: 0;
}

.rescues-carousel-wrapper {
  position: relative;
  max-width: 800px;
  margin: 0 auto;
}

.rescues-carousel {
  overflow: hidden;
  position: relative;
  border-radius: var(--radius);
}

.rescue-slide {
  display: none;
  opacity: 0;
  transition: opacity 0.6s ease-in-out;
}

.rescue-slide.active {
  display: block;
  opacity: 1;
}

.rescue-slide img {
  width: 100%;
  height: 400px;
  object-fit: cover;
  border-radius: var(--radius);
}

/* Rescues Carousel Navigation Buttons */
.rescues-carousel-btn {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  background: var(--akf-red);
  color: var(--akf-white);
  border: none;
  width: 56px;
  height: 56px;
  border-radius: 50%;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10;
  transition: background 0.3s;
}

.rescues-carousel-btn:hover {
  background: #c40f1f;
}

.rescues-carousel-btn svg {
  width: 24px;
  height: 24px;
}

.rescues-carousel-prev {
  left: -30px;
}

.rescues-carousel-next {
  right: -30px;
}

/* ---------- Parallax CTA ---------- */
.parallax-cta {
  position: relative;
  min-height: 70vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background-attachment: fixed;
  background-position: center;
  background-repeat: no-repeat;
  background-size: cover;
}

.parallax-overlay {
  position: absolute;
  inset: 0;
  background: rgba(33, 33, 33, 0.6);
}

.parallax-content {
  position: relative;
  z-index: 10;
  text-align: center;
  max-width: 768px;
  margin: 0 auto;
  padding: 5rem 1.5rem;
}

.parallax-content h2 {
  font-weight: 800;
  font-size: 4.5rem;
  color: var(--akf-white);
  line-height: 1.15;
  margin-bottom: 1.5rem;
}

.parallax-content p {
  color: rgba(255, 255, 255, 0.9);
  font-size: 1.25rem;
  line-height: 1.7;
  margin-bottom: 2.5rem;
}
/* Footer - Brand on left, multi-column links */
.site-footer {
  background: linear-gradient(180deg, #0b1a0f 30%, #045e1fd9 80%);
  color: #e8f1eb;
  padding: 4rem 3rem 2.5rem;
  margin-top: 0;
  border-top: 1px solid #0a3c14;
}

.footer-container {
  display: flex;
  gap: 4rem;
  max-width: 1400px;
  margin: 0 auto 3rem;
}

.footer-brand-column {
  flex: 0 0 280px;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.footer-brand {
  font-size: 1.4rem;
  font-weight: 700;
  color: #ffffff;
  margin: 0;
  display: flex;
  gap: 0.6rem;
  align-items: baseline;
}

.footer-brand-tag {
  font-size: 0.75rem;
  font-weight: 500;
  color: #b8d9bf;
  letter-spacing: 0.05em;
}

.footer-description {
  font-size: 0.9rem;
  color: #c5d9cc;
  line-height: 1.6;
  margin: 0;
}

.footer-socials {
  display: flex;
  gap: 0.7rem;
  margin-top: 0.5rem;
}

.social-link {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.1);
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  text-decoration: none;
  transition: all 0.3s ease;
  font-size: 0.8rem;
}

.social-link:hover {
  background: #4ade80;
  color: #000;
  transform: translateY(-2px);
}

.footer-link-group {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 3rem;
  flex: 1;
}

.footer-column {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.footer-section-title {
  font-size: 0.95rem;
  font-weight: 700;
  color: #ffffff;
  margin: 0;
  line-height: 1.4;
}

.footer-links {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.8rem;
}

.footer-links a {
  color: #c5d9cc;
  text-decoration: none;
  font-size: 0.9rem;
  transition: color 0.3s ease;
  line-height: 1.5;
}

.footer-links a:hover {
  color: #4ade80;
}

/* Footer bottom with side-by-side layout */
.footer-bottom {
  border-top: 1px solid #0a3c14;
  padding-top: 2rem;
}

.footer-bottom-content {
  display: flex;
  justify-content: space-between;
  align-items: center;
  max-width: 1400px;
  margin: 0 auto;
}

.footer-bottom-socials {
  display: flex;
  gap: 0.6rem;
}

.social-link-small {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.1);
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  text-decoration: none;
  transition: all 0.3s ease;
  font-size: 0.75rem;
}

.social-link-small:hover {
  background: #4ade80;
  color: #000;
  transform: translateY(-2px);
}

.footer-bottom p {
  color: #9db4a0;
  font-size: 0.85rem;
  margin: 0;
}

/* Responsive Design */
@media (max-width: 1024px) {
  .footer-link-group {
    grid-template-columns: repeat(2, 1fr);
    gap: 2rem;
  }

  .footer-container {
    gap: 2.5rem;
  }
}

/* ---------- Responsive ---------- */
@media (max-width: 1024px) {
  .how-to-adopt h2,
  .message-section h2,
  .adopt-grid h2 {
    font-size: 2.25rem;
  }

  .parallax-content h2 {
    font-size: 2.5rem;
  }

  .gallery-section h2 {
    font-size: 2.25rem;
  }
}

@media (max-width: 768px) {
  .container {
    padding: 0 1.25rem;
  }

  /* Header */
  .desktop-nav,
  .header-inner .btn-cta {
    display: none;
  }

  .mobile-toggle {
    display: block;
  }

  /* Steps */
  .steps-grid {
    grid-template-columns: 1fr;
    gap: 3rem;
  }

  /* Adopt */
  .adopt-grid {
    grid-template-columns: 1fr;
    gap: 2rem;
    min-height: auto;
  }

  .adopt-grid > div:first-child {
    min-height: auto;
    margin-bottom: 2rem;
  }

  .adopt-img {
    height: 300px;
  }

  .carousel-prev,
  .carousel-next {
    width: 48px;
    height: 48px;
  }

  .carousel-prev {
    left: 15px;
  }

  .carousel-next {
    right: 15px;
  }

  /* Gallery */
  .gallery-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  /* Parallax */
  .parallax-cta {
    background-attachment: scroll;
  }

  .parallax-content h2 {
    font-size: 2rem;
  }

  .parallax-content p {
    font-size: 1rem;
  }

  /* Footer */
  .footer-grid {
    grid-template-columns: 1fr;
  }

  .footer-bottom {
    flex-direction: column;
    gap: 1rem;
    text-align: center;
  }

  .how-to-adopt {
    padding: 3rem 0 5rem;
  }

  .adopt-section,
  .message-section,
  .reasons-to-adopt-section {
    padding: 3rem 0 4rem;
  }

  .how-to-adopt h2,
  .message-section h2,
  .reasons-title {
    font-size: 1.875rem;
    margin-bottom: 2rem;
  }

  .reasons-text {
    font-size: 1.1rem;
    margin-bottom: 1.5rem;
  }

  .adopt-grid h2 {
    font-size: 2rem;
  }

  .step h3 {
    font-size: 1.5rem;
  }

  .gallery-section h2 {
    font-size: 2rem;
  }

  .contact-footer h3 {
    font-size: 1.5rem;
  }
}

@media (max-width: 480px) {
  .gallery-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  .social-links {
    justify-content: center;
  }
}

@media (max-width: 430px) {
  .rescue-slide img { height: 200px; }
  .adopt-img { height: 200px; }
  .carousel-btn { width: 38px; height: 38px; }
  .carousel-prev { left: 5px; }
  .carousel-next { right: 5px; }
  .rescues-carousel-btn { width: 36px; height: 36px; }
  .rescues-carousel-prev { left: 5px; }
  .rescues-carousel-next { right: 5px; }
  .gallery-section h2 { font-size: 1.5rem; }
}
h1, h2, .hero h1, .hero-title, .page-title, .section-title { font-family:'Inter',sans-serif !important; font-size:clamp(3rem,2.6rem + 1vw,3.5rem) !important; line-height:1.1 !important; font-weight:800 !important; letter-spacing:-0.025em !important; color:var(--akf-dark) !important; }
.adopt-label { color:#000 !important; }
.carousel-btn { background:#000 !important; color:#fff !important; }
.parallax-content h2 { color:#fff !important; }
.meet-rescues-header h2 { font-size:2.25rem !important; line-height:1.35 !important; }
  </style>
  <link href="https://unpkg.com/aos@2.3.4/dist/aos.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body>

 <header class="site-header">
  <nav class="navbar">
    <a href="landing-page.php"><img src="uploads/site/DCVS LOGO.png" alt="DCSV Logo" class="brand-logo" style="height: 50px; width: auto;"></a>
    <button class="nav-toggle" aria-label="Toggle navigation">
      <span></span>
      <span></span>
      <span></span>
    </button>
    <ul class="nav-links">
      <li><a href="landing-page.php">Home</a></li>
      <li class="nav-item">
        <a href="javascript:void(0)" class="dropdown-toggle">Updates <span class="arrow">▾</span></a>
        <div class="dropdown-menu">
          <a href="announcements.php">Public Service Updates</a>
          <a href="geo-map-landing.php">Geo-Map</a>
        </div>
      </li>
      <li class="nav-item">
        <a href="about.php" class="dropdown-toggle">About <span class="arrow">▾</span></a>
        <div class="dropdown-menu">
          <a href="about.php">About Us</a>
          <a href="faq.php">FAQ</a>
          <a href="contact.php">Contact Us</a>
        </div>
      </li>
      <li><a href="#adopt" class="active">Adoption</a></li>
    </ul>
    <a href="login.php" class="nav-login-btn">Login</a>
  </nav>
</header>

  <!-- ==================== HERO (Bring Hope to Every Tail) ==================== -->
  <section class="adoption-hero-section">
    <div class="container">
      <div class="hero-grid">
        <div class="left-col" data-aos="fade-right">
          <img src="uploads/site/aspin4.jpeg" alt="Cat playing with toy">
          <blockquote>"Every gift gives hope. Every act of kindness saves a life."</blockquote>
        </div>
        <div class="right-col" data-aos="fade-left" data-aos-delay="150">
          <div class="top-photos">
            <img src="uploads/site/aspin2.gif" alt="Cat being held">
            <img src="uploads/site/aspin5.webp" alt="Dog being petted">
          </div>
          <h1>Bring Hope to Every Tail</h1>
          <p class="desc">At Dasmariñas City Veterinary Services, we're on a mission to bring comfort, care, and compassion to shelter animals across the city. Your support means full bellies, warm beds, life-saving treatments — and second chances for the animals who need them most.</p>
          <div class="cta-row">
            <a href="petlist.php" class="cta-btn">Adopt now <span class="icon">→</span></a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ==================== MEET THE HEARTS ==================== -->
  <section class="adoption-hearts-section">
    <div class="container">
      <div class="about-grid">
        <div class="about-col" data-aos="fade-right">
          <h1>Meet The Hearts Behind The Mission</h1>
          <p class="desc">Founded by a team of animal welfare advocates and veterinary professionals, Dasmariñas City Veterinary Services is more than a service — it's a community movement. Learn about our team, our values, and how we're building a future full of wagging tails and warm hearts.</p>
          <blockquote>"Every tail we touch is a life we change."</blockquote>
        </div>
        <div class="about-photos" data-aos="fade-left" data-aos-delay="150">
          <img class="tall" src="uploads/site/rescue1.webp" alt="Rescue animal portrait">
          <img class="short" src="uploads/site/rescue2.jpg" alt="Animal in care">
        </div>
      </div>
    </div>
  </section>

  <!-- ==================== HOW TO ADOPT ==================== -->
    <section class="how-to-adopt">
      <div class="container">
        <h2 data-aos="fade-up">How to Adopt from Dasmariñas City Veterinary Services?
        </h2>
        <div class="steps-grid">
          <div class="step" data-aos="fade-up" data-aos-delay="0">
            <h3>Register</h3>
            <p>Accomplish and fill out the DCVS Form to express your desire to adopt a chosen pet.</p>
          </div>
          <div class="step" data-aos="fade-up" data-aos-delay="150">
            <h3>Evaluation</h3>
            <p>Upon receipt of the form, we'll schedule an interview to assess if you and your chosen pet are a good match.</p>
          </div>
          <div class="step" data-aos="fade-up" data-aos-delay="300">
            <h3>Pick Up</h3>
            <p>Congratulations! You can pickup your adopted pet at our main office shelter.</p>
          </div>
        </div>
      </div>
    </section>

<!-- ==================== ADOPT SECTION ==================== -->
    <section class="adopt-section" id="adopt" data-aos="fade-up">
      <div class="container">
        <div class="adopt-carousel-wrapper">
          <!-- Carousel Container -->
          <div class="adopt-carousel">
            <!-- Slide 1: They Can't Wait to See You! -->
            <div class="adopt-slide active">
              <div class="adopt-grid">
                <div>
                  <span class="adopt-label">ADOPT</span>
                  <h2>They Can't Wait to See You!</h2>
                  <p>Rescued from cruelty and neglect, animals are brought in at the DCVS shelter for rehabilitation. Upon full recovery, we find them a new home and family to love them furever. #AdoptDontShop</p>
                </div>
                <div class="adopt-img">
                  <img src="uploads/site/rescue1.webp" alt="Happy rescue dog being petted" loading="lazy" width="800" height="600" />
                </div>
              </div>
            </div>

            <!-- Slide 2: Why Adopt a Rescue Dog? -->
            <div class="adopt-slide">
              <div class="adopt-grid">
                <div>
                  <span class="adopt-label">ADOPT</span>
                  <h2>Why Adopt a Rescue Pet?</h2>
                  <p>When you open your doors to welcome a rescue animal as part of your family, you're saving more than just one life. By adopting, you're helping make space for another animal in need and helping to give them the opportunity to become beloved pets. #AdoptDontShop</p>
                </div>
                <div class="adopt-img">
                  <img src="uploads/site/rescue2.jpg" alt="Happy dog with tongue out" loading="lazy" width="800" height="600" />
                </div>
              </div>
            </div>
          </div>

          <!-- Navigation Arrows -->
          <button class="carousel-btn carousel-prev" aria-label="Previous slide">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="15 18 9 12 15 6"></polyline>
            </svg>
          </button>
          <button class="carousel-btn carousel-next" aria-label="Next slide">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="9 18 15 12 9 6"></polyline>
            </svg>
          </button>

          <!-- Carousel Indicators -->
          <div class="carousel-indicators">
            <button class="indicator active" data-slide="0" aria-label="Slide 1"></button>
            <button class="indicator" data-slide="1" aria-label="Slide 2"></button>
          </div>
        </div>
      </div>
    </section>

  <!-- ==================== REASONS TO ADOPT ==================== -->
    <section class="reasons-to-adopt-section" data-aos="fade-up">
      <div class="container">
        <h2 class="reasons-title">Reasons Why You Should Adopt, Don't Shop!</h2>
        <div class="reasons-content">
          <p class="reasons-text">
            When you choose to adopt a pet, you're not just bringing home a companion - you're saving a life and making a powerful statement against commercial breeding practices. Every year, millions of wonderful animals end up in shelters waiting for someone like you to give them a second chance at happiness.
          </p>
          <div class="reasons-image">
            <img src="uploads/site/message.jpg" alt="Happy adopters with their rescue pets" loading="lazy" width="800" height="500" />
          </div>
          <p class="reasons-text">
            Shelter animals come in all shapes, sizes, and personalities - each with a unique story and endless capacity for love. By adopting, you're breaking the cycle of pet overpopulation while gaining a loyal friend who will forever be grateful for your kindness. Remember, the best pets aren't bought - they're chosen.
          </p>
        </div>
      </div>
    </section>

  <!-- ==================== GALLERY ==================== -->
    <section class="gallery-section" data-aos="fade-up">
      <div class="container">
        <h2>WHERE WE<br>WORK.</h2>
      </div>
      <div class="gallery-grid">
        <div class="gallery-item">
          <img src="uploads/site/wherewework6.jpg" alt="Volunteers with rescued dogs at an event" loading="lazy" width="640" height="640" />
        </div>
        <div class="gallery-item">
          <img src="uploads/site/wherewework2.jpg" alt="Volunteer holding a rescue puppy" loading="lazy" width="640" height="640" />
        </div>
        <div class="gallery-item">
          <img src="uploads/site/wherewework8.jpg" alt="Animal rescue advocacy campaign" loading="lazy" width="640" height="640" />
        </div>
        <div class="gallery-item">
          <img src="uploads/site/wherewework5.jpg" alt="Rescue dogs at the shelter" loading="lazy" width="640" height="640" />
        </div>
        <div class="gallery-item">
          <img src="uploads/site/wherewework7.jpg" alt="Volunteer with a black rescue puppy" loading="lazy" width="640" height="640" />
        </div>
      </div>
    </section>
  </div>

  <!-- ==================== PARALLAX CTA ==================== -->
  <section class="parallax-cta" style="background-image: url('uploads/site/heroo.png');">
    <div class="parallax-overlay"></div>
    <div class="parallax-content">
      <h2>Want to Participate in Adopting?</h2>
      <p>There are many ways to adopt. You can help improve the lives of our beloved animals.</p>
     
    </div>
  </section>

  <!-- ==================== MEET OUR RESCUES ==================== -->
    <section class="meet-rescues-section" data-aos="fade-up">
      <div class="container">
        <div class="meet-rescues-header">
          <h2>At our animal shelter, we believe that compassion has the power to change lives. Every animal deserves a loving home, and our organization is committed to making that a reality.</h2>
        </div>
        
        <div class="rescues-carousel-wrapper">
          <div class="rescues-carousel">
            <!-- Slide 1 -->
            <div class="rescue-slide active">
              <img src="uploads/site/wherewework.jpg" alt="Rescue 1" loading="lazy" width="400" height="400" />
            </div>
            <!-- Slide 2 -->
            <div class="rescue-slide">
              <img src="uploads/site/wherewework2.jpg" alt="Rescue 2" loading="lazy" width="400" height="400" />
            </div>
            <!-- Slide 3 -->
            <div class="rescue-slide">
              <img src="uploads/site/wherewework3.jpg" alt="Rescue 3" loading="lazy" width="400" height="400" />
            </div>
            <!-- Slide 4 -->
            <div class="rescue-slide">
              <img src="uploads/site/wherewework4.jpg" alt="Rescue 4" loading="lazy" width="400" height="400" />
            </div>
            <!-- Slide 5 -->
            <div class="rescue-slide">
              <img src="uploads/site/wherewework5.jpg" alt="Rescue 5" loading="lazy" width="400" height="400" />
            </div>
            <!-- Slide 6 -->
            <div class="rescue-slide">
              <img src="uploads/site/wherewework6.jpg" alt="Rescue 6" loading="lazy" width="400" height="400" />
            </div>
            <!-- Slide 7 -->
            <div class="rescue-slide">
              <img src="uploads/site/wherewework7.jpg" alt="Rescue 7" loading="lazy" width="400" height="400" />
            </div>
          </div>

        </div>

        <div class="meet-rescues-footer">
          <a href="petlist.php" class="meet-rescues-link">
            <h3>Meet our rescues!</h3>
          </a>
        </div>
      </div>
    </section>

 <!-- Footer -->
  <footer class="site-footer" data-aos="fade-up">
    <div class="footer-container">
      <div class="footer-column footer-brand-column">
        <h4 class="footer-brand">
          Dasmariñas City Veterinary Services<span class="footer-brand-tag">DCVS</span>
        </h4>
        <p class="footer-description">
          Contact for any help & support or business related query of vetdasma@yahoo.com
        </p>
        <div class="footer-socials">
          <a href="#" class="social-link">f</a>
          <a href="#" class="social-link">G</a>
          <a href="#" class="social-link">y</a>
        </div>
      </div>

      <div class="footer-link-group">
        <div class="footer-column">
          <h4 class="footer-section-title">Pet Registration & Identification
</h4>
          <ul class="footer-links">
            <li><a href="#banking">Rabies Vaccination Monitoring
</a></li>
            <li><a href="#legal">Animal Health & Welfare Services</a></li>
            <li><a href="#supply">Stray and At-Risk Animal Rescue Reporting
</a></li>
            <li><a href="#retail">Geo-Mapped Incident Tracking
</a></li>
          </ul>
        </div>

        <div class="footer-column">
          <h4 class="footer-section-title">Barangay-Level Animal Data Management
Food Safety & Pharmaceuticals</h4>
          <ul class="footer-links">
            <li><a href="#healthcare">Public Veterinary Assistance Programs
</a></li>
            <li><a href="#lims">Disease Prevention & Control
</a></li>
            <li><a href="#real-estate">Responsible Pet Ownership Support
</a></li>
            <li><a href="#medical">Animal Population Monitoring
</a></li>
          </ul>
        </div>

        <div class="footer-column">
          <h4 class="footer-section-title">Citywide Veterinary Information System
</h4>
          <ul class="footer-links">
            <li><a href="#drugs">Community-Based Animal Welfare Initiatives</a></li>
          </ul>
        </div>
      </div>
    </div>

    <div class="footer-bottom">
      <div class="footer-bottom-content">
        <div class="footer-bottom-socials">
          <a href="#" class="social-link-small">f</a>
          <a href="#" class="social-link-small">G</a>
          <a href="#" class="social-link-small">y</a>
        </div>
        <p>&copy; 2025 Dasmariñas City Veterinary Services. All rights reserved. | Compliance matters here.</p>
      </div>
    </div>
  </footer>
  <script src="https://unpkg.com/aos@2.3.4/dist/aos.js"></script>
  <script>
document.addEventListener('DOMContentLoaded', () => {
  AOS.init({ duration: 700, easing: 'ease-out-cubic', once: true, offset: 60 });
  const mobileToggle = document.getElementById('mobile-toggle');
  const mobileNav = document.getElementById('mobile-nav');
  const hamburgerIcon = document.getElementById('icon-hamburger');
  const closeIcon = document.getElementById('icon-close');

  if (mobileToggle && mobileNav) {
    mobileToggle.addEventListener('click', () => {
      const isOpen = mobileNav.classList.toggle('open');
      hamburgerIcon.style.display = isOpen ? 'none' : 'block';
      closeIcon.style.display = isOpen ? 'block' : 'none';
    });

    mobileNav.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        mobileNav.classList.remove('open');
        hamburgerIcon.style.display = 'block';
        closeIcon.style.display = 'none';
      });
    });
  }

  const contactForm = document.getElementById('contact-form');
  if (contactForm) {
    contactForm.addEventListener('submit', (e) => {
      e.preventDefault();
      alert('Thank you for reaching out!');
      contactForm.reset();
    });
  }

  const backToTop = document.getElementById('back-to-top');
  if (backToTop) {
    backToTop.addEventListener('click', (e) => {
      e.preventDefault();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  // Dropdown toggle on mobile
  document.querySelectorAll('.dropdown-toggle').forEach(function(t){
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

  const adoptCarousel = {
    slides: document.querySelectorAll('.adopt-slide'),
    prevBtn: document.querySelector('.carousel-prev'),
    nextBtn: document.querySelector('.carousel-next'),
    indicators: document.querySelectorAll('.indicator'),
    currentSlide: 0,

    init() {
      if (this.slides.length === 0) return;

      this.prevBtn?.addEventListener('click', () => this.prevSlide());
      this.nextBtn?.addEventListener('click', () => this.nextSlide());
      
      this.indicators.forEach((indicator, index) => {
        indicator.addEventListener('click', () => this.goToSlide(index));
      });

      this.showSlide(0);
    },

    showSlide(index) {
      this.slides.forEach(slide => slide.classList.remove('active'));
      this.indicators.forEach(indicator => indicator.classList.remove('active'));

      this.slides[index].classList.add('active');
      this.indicators[index].classList.add('active');
      this.currentSlide = index;
    },

    nextSlide() {
      const nextIndex = (this.currentSlide + 1) % this.slides.length;
      this.showSlide(nextIndex);
    },

    prevSlide() {
      const prevIndex = (this.currentSlide - 1 + this.slides.length) % this.slides.length;
      this.showSlide(prevIndex);
    },

    goToSlide(index) {
      this.showSlide(index);
    }
  };

  adoptCarousel.init();

  const rescuesCarousel = {
    slides: document.querySelectorAll('.rescue-slide'),
    prevBtn: document.querySelector('.rescues-carousel-prev'),
    nextBtn: document.querySelector('.rescues-carousel-next'),
    currentSlide: 0,

    init() {
      if (this.slides.length === 0) return;

      this.prevBtn?.addEventListener('click', () => {
        this.prevSlide();
      });
      this.nextBtn?.addEventListener('click', () => {
        this.nextSlide();
      });

      this.startAutoSlide();

      this.showSlide(0);
    },

    startAutoSlide() {
      this.autoSlideInterval = setInterval(() => {
        this.nextSlide();
      }, 3000);
    },

    showSlide(index) {
      this.slides.forEach(slide => slide.classList.remove('active'));

      this.slides[index].classList.add('active');
      this.currentSlide = index;
    },

    nextSlide() {
      const nextIndex = (this.currentSlide + 1) % this.slides.length;
      this.showSlide(nextIndex);
    },

    prevSlide() {
      const prevIndex = (this.currentSlide - 1 + this.slides.length) % this.slides.length;
      this.showSlide(prevIndex);
    }
  };

  rescuesCarousel.init();
});
// Hamburger menu toggle
(function() {
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
})();
  </script>
  </body>
</html>
