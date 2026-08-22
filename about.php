<?php $pageTitle = 'About Us'; $activePage = 'about'; include 'includes/header.php'; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
.about-page{background:#fff;padding-top:100px;}
.about-page .wrap{max-width:1200px;margin:0 auto;padding:0 32px;}

/* HERO */
.about-hero{text-align:center;padding:80px 32px 40px;}
.about-hero h1{
  font-weight:800;font-size:3rem;color:#045e1f;font-family:'Montserrat',sans-serif;
  max-width:900px;margin:0 auto 1.5rem;line-height:1.15;
}
.about-hero p{max-width:560px;margin:0 auto;color:#212121;font-size:1.125rem;line-height:1.7;}
.photo{
  border-radius:16px;overflow:hidden;
  border:1px solid rgba(0,0,0,0.35);
  box-shadow:0 2px 8px rgba(0,0,0,0.06),0 12px 24px rgba(0,0,0,0.08);
  background:#f0f0f0;display:block;
}
.photo img{width:100%;height:100%;object-fit:cover;display:block;}
.hero-photo{margin-top:56px;max-width:1100px;margin-left:auto;margin-right:auto;aspect-ratio:16/8.6;}
.caption{text-align:center;color:#6b7280;font-size:13px;margin:14px 0 0;}

.about-page section{padding:80px 32px;}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;}
@media(max-width:800px){.two-col{grid-template-columns:1fr;}}
.about-page h2{font-size:3rem;font-weight:800;color:#045e1f;font-family:'Montserrat',sans-serif;line-height:1.15;margin:0 0 1.5rem;}
.body-text p{color:#212121;font-size:1.125rem;line-height:1.7;margin:0 0 16px;}
.side-photo{aspect-ratio:1/1;border-radius:16px;}

/* culture */
.culture-grid{display:grid;grid-template-columns:1.1fr 0.9fr;gap:50px;margin-bottom:8px;}
@media(max-width:800px){.culture-grid{grid-template-columns:1fr;}}
.hq-box{background:#f3f8ff;border-radius:14px;padding:28px;font-size:1.125rem;color:#212121;line-height:1.7;align-self:start;}

/* photo stack */
.photo-stack{
  position:relative;width:100%;max-width:1136px;margin:36px auto 0;aspect-ratio:1032/814;
}
.photo-stack .photo{
  position:absolute;border-radius:16px;border:1px solid rgba(0,0,0,0.25);
  box-shadow:0 2px 8px rgba(0,0,0,0.06),0 12px 24px rgba(0,0,0,0.08);
}
.photo-stack .p1{left:0%;top:0%;width:40.5%;height:65%;z-index:1;}
.photo-stack .p2{left:41.7%;top:18%;width:58.3%;height:56.5%;z-index:2;}
.photo-stack .p3{left:59.3%;top:62%;width:32%;height:38%;z-index:3;}
.stack-caption{margin-top:14px;font-size:13px;color:#6b7280;}

/* numbers */
.numbers-intro{text-align:center;max-width:700px;margin:0 auto 50px;}
.numbers-intro h2{font-size:3rem;font-weight:800;color:#045e1f;font-family:'Montserrat',sans-serif;}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;}
@media(max-width:800px){.stats-row{grid-template-columns:1fr;}}
.stat-card{background:#f3f8ff;border-radius:16px;padding:28px;}
.icon-badge{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:18px;}
.stat-card h3{font-size:2.25rem;margin:0 0 12px;font-weight:700;color:#212121;}
.stat-card p{font-size:1rem;color:#4b5563;line-height:1.7;margin:0;}

/* building */
.building-intro{text-align:center;max-width:760px;margin:0 auto 60px;}
.building-intro h2{font-size:3rem;font-weight:800;color:#045e1f;font-family:'Montserrat',sans-serif;}
.building-intro p{color:#212121;font-size:1.125rem;line-height:1.7;}

/* CTA before footer */
.about-cta{text-align:center;padding:60px 32px 80px;background:#fff;}
.about-cta h2{font-size:2rem;font-weight:800;color:#045e1f;font-family:'Montserrat',sans-serif;margin:0 0 24px;}
.about-cta .cta-link{display:inline-block;padding:14px 36px;background:#045e1f;color:#fff;border-radius:8px;font-weight:600;font-size:1rem;text-decoration:none;transition:background 0.3s;}
.about-cta .cta-link:hover{background:#047a28;}

/* animations */
.fade-up{opacity:0;transform:translateY(40px);transition:opacity 0.8s ease-out,transform 0.8s ease-out;}
.fade-up.visible{opacity:1;transform:translateY(0);}
.fade-up-delay-1{transition-delay:0.1s;}
.fade-up-delay-2{transition-delay:0.2s;}
.fade-up-delay-3{transition-delay:0.3s;}

@media(max-width:900px){
  .about-hero h1{font-size:42px;}
  .about-page h2{font-size:28px;}
  .stat-card h3{font-size:28px;}
}
</style>

<div class="about-page">

<section class="about-hero">
  <h1 class="fade-up">We're committed<br>to Animal Welfare</h1>
  <p class="fade-up fade-up-delay-1">The Dasmariñas City Veterinary Services (DCVS) is dedicated to protecting the health and welfare of animals and residents across all 75 barangays of Dasmariñas City through responsive reporting, community coordination, and accessible veterinary services.</p>
  <div class="photo hero-photo fade-up fade-up-delay-2">
    <img src="uploads/site/about-us-1.jpg" alt="Team walking">
  </div>
</section>

<section class="fade-up">
  <div class="wrap two-col">
    <div class="body-text">
      <h2>Care is at the heart of what we do</h2>
      <p>Our goal is to make animal welfare services accessible to every resident and barangay in Dasmariñas City. But, for us, it doesn't stop there.</p>
      <p>From stray animal reports to rabies prevention, we're working to close the gap between the community and the veterinary services that protect them. This platform reflects that same commitment connecting residents, barangay officials, and DCVS staff through one coordinated system.</p>
    </div>
    <div class="photo side-photo">
      <img src="uploads/site/about-us-2.jpg" alt="Team member portrait">
    </div>
  </div>
</section>

<section class="fade-up">
  <div class="wrap culture-grid">
    <div class="body-text">
      <h2>DCVS works hand in hand with every barangay</h2>
      <p>Every report we receive, every case we confirm, and every service we provide represents a resident, a pet, or a community under our care.</p>
      <p>That's why close coordination with barangay officials remains central to how we operate ensuring every concern is heard and every response is timely.</p>
    </div>
    <div class="hq-box">DCVS serves all 75 barangays of Dasmariñas City, working directly with barangay officials, pet owners, and residents to strengthen animal welfare and public health across the city.</div>
  </div>
  <div class="wrap">
    <div class="photo-stack">
      <div class="photo p1"><img src="uploads/site/about-us-3.jpg" alt="Team photo"></div>
      <div class="photo p2"><img src="uploads/site/about-us-4.jpg" alt="Team member"></div>
      <div class="photo p3"><img src="uploads/site/about-us-5.jpg" alt="Team member"></div>
    </div>
  </div>
</section>

<section class="fade-up">
  <div class="wrap numbers-intro">
    <h2>What We Do</h2>
  </div>
  <div class="wrap stats-row">
    <div class="stat-card fade-up fade-up-delay-1">
      <div class="icon-badge" style="background:#f5f5f5;color:#0d0d0d;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      </div>
      <h3 class="count-up" data-target="2026" data-format="plain">0</h3>
      <p>Served Dasmariñas City residents through animal welfare programs, vaccination drives, and rabies response initiatives.</p>
    </div>
    <div class="stat-card fade-up fade-up-delay-2">
      <div class="icon-badge" style="background:#f5f5f5;color:#0d0d0d;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      </div>
      <h3 class="count-up" data-target="50" data-format="plus">0</h3>
      <p>Barangays across the city fall under our coverage and coordination.</p>
    </div>
    <div class="stat-card fade-up fade-up-delay-3">
      <div class="icon-badge" style="background:#f5f5f5;color:#0d0d0d;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
      </div>
      <h3 class="count-up" data-target="10000" data-format="comma-plus">0</h3>
      <p>Core services we provide: reporting, pet registration, adoption, and veterinary appointments.</p>
    </div>
  </div>
</section>

<section class="fade-up">
  <div class="wrap building-intro">
    <h2>What we're building</h2>
    <p>DCVS manages pet registration, vaccination monitoring, animal rescue, and response to rabies-related incidents across the city. We work closely with barangay officials to validate community reports, confirm cases, and ensure that every concern big or small reaches the right people quickly and accurately.</p>
  </div>
  <div class="wrap two-col fade-up" style="margin-bottom:80px;">
    <div class="body-text">
      <h2>Report with confidence</h2>
      <p>We encourage residents to report stray animals and suspected rabies cases with clear details location, description, and condition so our team can respond quickly and appropriately, wherever the concern happens to be.</p>
      <p>Every report is verified before action is taken. To protect the accuracy of our records and the trust of our community, every report submitted goes through barangay validation before our office confirms it as an official case. This keeps our response focused on real, verified concerns.</p>
    </div>
    <div class="photo side-photo">
      <img src="uploads/site/about-us-6.jpg" alt="Team at work">
    </div>
  </div>
  <div class="wrap two-col fade-up">
    <div class="photo side-photo">
      <img src="uploads/site/about-us-7.jfif" alt="Office environment">
    </div>
    <div class="body-text">
      <h2>Responsible pet ownership starts here</h2>
      <p>We give pet owners the tools to keep their animals healthy and protected from tracking vaccination and kapon records to registering pets under one profile. No more lost paperwork or missed vaccination schedules, just a clear, complete record for every pet in your care.</p>
    </div>
  </div>
</section>

</div>

<section class="about-cta">
  <h2>Do you have a question?</h2>
  <a href="faq.php" class="cta-link">Visit our FAQ</a>
</section>

<script>
(function(){
  const fadeElements = document.querySelectorAll('.fade-up');
  const fadeObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if(entry.isIntersecting){
        entry.target.classList.add('visible');
        fadeObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0.15, rootMargin: '0px 0px -50px 0px' });
  fadeElements.forEach(el => fadeObserver.observe(el));

  function formatValue(val, format){
    if(format === 'plain') return String(val);
    if(format === 'plus') return val + '+';
    if(format === 'comma-plus') return val.toLocaleString('en-US') + '+';
    return String(val);
  }

  function animateCount(el){
    const target = parseInt(el.getAttribute('data-target'), 10);
    const format = el.getAttribute('data-format');
    const duration = 900;
    const startTime = performance.now();
    function tick(now){
      const elapsed = now - startTime;
      const progress = Math.min(elapsed / duration, 1);
      const eased = 1 - Math.pow(1 - progress, 3);
      const current = Math.floor(eased * target);
      el.textContent = formatValue(current, format);
      if(progress < 1){
        requestAnimationFrame(tick);
      } else {
        el.textContent = formatValue(target, format);
      }
    }
    requestAnimationFrame(tick);
  }

  const counters = document.querySelectorAll('.count-up');
  if(!counters.length) return;
  const counterObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if(entry.isIntersecting){
        animateCount(entry.target);
        counterObserver.unobserve(entry.target);
      }
    });
  }, { threshold: 0.4 });
  counters.forEach(el => counterObserver.observe(el));
})();
</script>

<?php include 'includes/footer.php'; ?>
</body>
</html>
