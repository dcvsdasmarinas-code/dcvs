<?php $pageTitle = 'Adopt a Pet'; $activePage = 'adoption'; include __DIR__ . '/../includes/header.php'; ?>

<style>
.adoption-two-page{background:#f7f6f4;min-height:100vh;padding-top:100px;}
.adoption-two-page .wrap{max-width:1100px;margin:0 auto;padding:56px 32px;}
.adoption-two-page *{font-family:'Inter',system-ui,sans-serif !important;}

.hero-grid{
  display:grid;grid-template-columns:1.05fr 1fr;gap:40px;align-items:start;
}
@media(max-width:860px){.hero-grid{grid-template-columns:1fr;}}

.left-col img{
  width:100%;aspect-ratio:350/300;object-fit:cover;border-radius:16px;display:block;background:#e4e1db;
}
blockquote{
  margin:22px 0 0;padding-left:18px;border-left:3px solid #cfcac0;
  font-style:italic;font-size:17px;color:#5a6270;line-height:1.5;
}

.right-col{display:flex;flex-direction:column;}
.top-photos{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:32px;}
.top-photos img{width:100%;aspect-ratio:1/1.2;object-fit:cover;border-radius:14px;display:block;background:#e4e1db;}

h1{font-weight:800;font-size:52px;line-height:1.05;letter-spacing:-0.025em;margin:0 0 20px;}
.right-col p.desc{font-size:15px;color:#5a6270;max-width:480px;margin:0 0 26px;}

.cta-row{display:flex;gap:14px;flex-wrap:wrap;}
.cta-btn{
  display:inline-flex;align-items:center;gap:10px;height:44px;padding:0 24px;
  border-radius:6px;border:1px solid transparent;cursor:pointer;
  font-weight:500;font-size:15px;color:#fafafa;background:#18181b;
  box-shadow:0 1px 2px 0 rgba(0,0,0,0.05);transition:background .15s;
}
.cta-btn:hover{background:#27272a;}
.cta-btn .icon{width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;}

.about-grid{display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:center;}
@media(max-width:860px){.about-grid{grid-template-columns:1fr;}}
.badge{
  display:inline-block;background:#f3dbe0;color:#7a1f2b;
  font-weight:600;font-size:13.5px;padding:7px 16px;border-radius:20px;margin-bottom:20px;
}
.about-col h1{font-size:44px;font-weight:700;line-height:1.15;letter-spacing:-0.025em;margin:0 0 22px;}
.about-col p.desc{font-size:15px;color:#5a6270;max-width:460px;margin:0 0 24px;}
.about-col blockquote{margin:0 0 30px;}
.about-photos{display:grid;grid-template-columns:1.3fr 1fr;gap:24px;align-items:end;}
.about-photos img{width:100%;object-fit:cover;border-radius:16px;display:block;background:#e4e1db;}
.about-photos .tall{aspect-ratio:1/1.2;}
.about-photos .short{aspect-ratio:1/0.9;margin-bottom:40px;}
@media(max-width:860px){.about-photos .short{margin-bottom:0;}}
@media(max-width:520px){h1{font-size:38px;}}
</style>

<div class="adoption-two-page">

<section class="wrap">
  <div class="hero-grid">
    <div class="left-col">
      <img src="uploads/site/aspin4.jpeg" alt="Cat playing with toy">
      <blockquote>"Every gift gives hope. Every act of kindness saves a life."</blockquote>
    </div>
    <div class="right-col">
      <div class="top-photos">
        <img src="uploads/site/aspin2.gif" alt="Cat being held">
        <img src="uploads/site/aspin5.webp" alt="Dog being petted">
      </div>
      <h1>Bring Hope to Every Tail</h1>
      <p class="desc">At Dasmariñas City Veterinary Services, we're on a mission to bring comfort, care, and compassion to shelter animals across the city. Your support means full bellies, warm beds, life-saving treatments — and second chances for the animals who need them most.</p>
      <div class="cta-row">
        <a href="adoption.php" class="cta-btn">Adopt now<span class="icon">→</span></a>
      </div>
    </div>
  </div>
</section>

<section class="wrap">
  <div class="about-grid">
    <div class="about-col">
      <h1>Meet The Hearts Behind The Mission</h1>
      <p class="desc">Founded by a team of animal welfare advocates and veterinary professionals, Dasmariñas City Veterinary Services is more than a service — it's a community movement. Learn about our team, our values, and how we're building a future full of wagging tails and warm hearts.</p>
      <blockquote>"Every tail we touch is a life we change."</blockquote>
    </div>
    <div class="about-photos">
      <img class="tall" src="uploads/site/rescue1.webp" alt="Rescue animal portrait">
      <img class="short" src="uploads/site/rescue2.jpg" alt="Animal in care">
    </div>
  </div>
</section>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
