<!-- Footer -->
<footer class="site-footer">
  <div class="footer-container">
    <div class="footer-column footer-brand-column">
      <h4 class="footer-brand">Dasmariñas City Veterinary Services<span class="footer-brand-tag">DCVS</span></h4>
      <p class="footer-description">Contact for any help & support or business related query at vetdasma@yahoo.com</p>
      <div class="footer-socials">
        <a href="." class="social-link">f</a>
        <a href="." class="social-link">G</a>
        <a href="." class="social-link">y</a>
      </div>
    </div>
    <div class="footer-link-group">
      <div class="footer-column">
        <h4 class="footer-section-title">Explore the System</h4>
        <ul class="footer-links">
          <li><a href="landing-page.php">Explore the Platform</a></li>
          <li><a href="landing-page.php#capabilities">System Capabilities</a></li>
          <li><a href="announcements.php">Public Service Updates</a></li>
          <li><a href="geo-map-landing.php">Geo-Mapping Dashboard</a></li>
        </ul>
      </div>
      <div class="footer-column">
        <h4 class="footer-section-title">About the Veterinary Office</h4>
        <ul class="footer-links">
          <li><a href="about.php">About DCVS</a></li>
          <li><a href="faq.php">Frequently Asked Questions</a></li>
          <li><a href="contact.php">Contact Us</a></li>
        </ul>
      </div>
      <div class="footer-column">
        <h4 class="footer-section-title">Services &amp; Insights</h4>
        <ul class="footer-links">
          <li><a href="adoption.php">Pet Adoption Services</a></li>
          <li><a href="Knowledge-Hub.php">Knowledge Hub</a></li>
          <li><a href="Philippine-Initiatives.php">Philippine Initiatives</a></li>
        </ul>
      </div>
    </div>
  </div>
  <div class="footer-bottom">
    <div class="footer-bottom-content">
      <div class="footer-bottom-socials">
        <a href="." class="social-link-small">f</a>
        <a href="." class="social-link-small">G</a>
        <a href="." class="social-link-small">y</a>
      </div>
      <p>&copy; 2025 Dasmariñas City Veterinary Services. All rights reserved.</p>
    </div>
  </div>
</footer>

<style>
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
  width: 32px; height: 32px;
  border-radius: 50%;
  background: rgba(255,255,255,0.1);
  color: #ffffff;
  display: flex; align-items: center; justify-content: center;
  font-weight: 600; text-decoration: none;
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
.footer-links a:hover { color: #4ade80; }
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
.footer-bottom-socials { display: flex; gap: 0.6rem; }
.social-link-small {
  width: 28px; height: 28px;
  border-radius: 50%;
  background: rgba(255,255,255,0.1);
  color: #ffffff;
  display: flex; align-items: center; justify-content: center;
  font-weight: 600; text-decoration: none;
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
@media (max-width: 1024px) {
  .footer-link-group { grid-template-columns: repeat(2, 1fr); gap: 2rem; }
  .footer-container { gap: 2.5rem; }
}
@media (max-width: 768px) {
  .footer-container { flex-direction: column; gap: 2.5rem; }
  .footer-link-group { grid-template-columns: 1fr; gap: 1.5rem; }
  .footer-bottom-content { flex-direction: column; gap: 1rem; }
  .footer-brand-column { flex: 1; }
}
</style>
