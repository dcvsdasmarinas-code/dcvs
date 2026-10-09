<?php $pageTitle = 'FAQ'; $activePage = 'faq'; include 'includes/header.php'; ?>

<style>
.faq-page{background:#f5f5f7;min-height:100vh;padding-top:100px;}
.faq-page .page{max-width:1064px;margin:0 auto;padding:0 16px;}
.faq-page .wrap{max-width:1000px;margin:0 auto;padding:0 32px;}
.faq-page a{color:inherit;}

.faq-page *{font-family:'Inter',system-ui,sans-serif !important;}

/* HERO */
.faq-hero{
  background:#fff;border-radius:24px;margin:6px 0 24px;padding:56px 48px 48px;
  position:relative;overflow:hidden;
}
.faq-hero .eyebrow{font-size:12.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#8a93ab;margin-bottom:14px;}
.faq-hero h1{
  font-weight:800;font-size:48px;line-height:1.1;letter-spacing:-0.025em;
  margin:0 0 32px;max-width:750px;
}
.hero-cols{display:grid;grid-template-columns:1fr auto 1fr;gap:24px;align-items:center;}
.hero-cols p{font-size:15px;color:#4b5568;margin:0;max-width:320px;}
.card-stack{position:relative;width:220px;height:150px;justify-self:center;}
.stack-card{
  position:absolute;background:#fff;border-radius:10px;box-shadow:0 8px 20px rgba(20,30,60,.10);
  font-size:11px;font-weight:600;display:flex;align-items:center;padding:0 12px;
}
.stack-card.c1{width:200px;height:46px;left:6px;top:76px;transform:rotate(-9deg);color:#8a93ab;}
.stack-card.c2{width:200px;height:40px;left:16px;top:36px;transform:rotate(-1deg);background:#eef2ff;}
.stack-card.c3{width:200px;height:34px;left:34px;top:0;transform:rotate(10deg);background:#22c55e;color:#fff;}
@media(max-width:800px){
  .hero-cols{grid-template-columns:1fr;text-align:left;}
  .card-stack{justify-self:start;margin:20px 0;}
  .faq-hero h1{font-size:36px;}
}

/* ACCORDION */
.faq-section{
  border-radius:24px;margin:0 0 24px;padding:52px 48px;
}
.faq-section.blue{background:#fff;}
.faq-section.purple{background:#fff;}
.faq-section h2{font-size:38px;font-weight:800;margin:0 0 28px;letter-spacing:-0.025em;}
.faq-item{border-bottom:1px solid rgba(0,0,0,0.1);}
.faq-item:first-of-type{border-top:1px solid rgba(0,0,0,0.1);}
.faq-q{
  width:100%;background:none;border:none;
  display:flex;align-items:center;justify-content:space-between;text-align:left;
  font-size:16.5px;font-weight:600;
  padding:20px 0;cursor:pointer;gap:16px;
}
.faq-q .arrow{flex-shrink:0;width:22px;height:22px;display:flex;align-items:center;justify-content:center;transition:transform .25s ease;}
.faq-item.open .faq-q .arrow{transform:rotate(180deg);}
.faq-a{max-height:0;overflow:hidden;transition:max-height .3s ease,padding .3s ease;}
.faq-item.open .faq-a{max-height:200px;padding-bottom:20px;}
.faq-a p{margin:0;font-size:15px;color:#4b5568;max-width:600px;font-weight:400;}

/* CONTACT CTA */
.contact-cta{
  padding:56px 48px 40px;background:#fff;border-radius:24px;margin:0 0 24px;
}
.contact-cta .eyebrow{margin-bottom:12px;}
.contact-cta p{font-size:30px;font-weight:800;line-height:1.3;margin:0;max-width:640px;letter-spacing:-0.025em;}
.contact-cta p .thin{font-weight:400;}
.contact-cta a.email-link{
  display:inline-flex;align-items:center;gap:8px;color:#0f5c4c;
  font-weight:600;font-size:16px;text-decoration:none;margin-top:18px;
}
.contact-cta a.email-link:hover{text-decoration:underline;}

@media(max-width:900px){
  .faq-hero h1{font-size:36px;}
}
@media(max-width:700px){
  .faq-section{padding:32px 24px;}
  .faq-hero{padding:32px 24px;}
  .contact-cta{padding:32px 24px;}
  .faq-section h2{font-size:28px;}
  .contact-cta p{font-size:24px;}
  .faq-item.open .faq-a{max-height:none;}
}
@media(max-width:430px){
  .faq-hero{padding:24px 16px;}
  .faq-section{padding:24px 16px;}
  .faq-hero h1{font-size:1.75rem;}
  .faq-section h2{font-size:1.4rem;}
  .contact-cta p{font-size:1.2rem;}
}
.faq-hero h1{font-size:clamp(3rem,2.6rem + 1vw,4rem) !important;}
.faq-section h2{font-size:clamp(1.6rem,1.4rem + 0.75vw,2.2rem) !important;}
</style>

<div class="faq-page">
<div class="page">

<section class="faq-hero" style="max-width:1000px;margin-left:auto;margin-right:auto;">
  <div class="eyebrow">FAQ</div>
  <h1>Everything You Need to Know About DCVS Services</h1>
  <div class="hero-cols">
    <p>Find answers to the most frequently asked questions about the Dasmariñas City Veterinary Services online platform. Learn how to register your pets, submit reports, request appointments, adopt pets, and track your requests with ease.</p>
    <div class="card-stack">
      <div class="stack-card c1">Frequently Asked Questions</div>
      <div class="stack-card c2">Pet Registration</div>
      <div class="stack-card c3">Appointments & Reports</div>
    </div>
    <p>Browse helpful information about the services available through DCVS. Whether you are registering a pet, reporting stray or rabies cases, scheduling veterinary appointments, or checking the status of your requests, this section provides quick answers to guide you through every step.</p>
  </div>
</section>

<section class="faq-section blue" style="max-width:1000px;margin-left:auto;margin-right:auto;">
  <h2>General</h2>
  <div class="faq-list">
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">How do I register my pet in the system?<span class="arrow">▾</span></button><div class="faq-a"><p>You can register your pet by logging into your Resident account and selecting Register a Pet. Fill in your pet's information, upload a photo if available, and submit the registration. Once saved, your pet profile will appear in the My Pets section.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">How do I submit a stray animal or rabies report?<span class="arrow">▾</span></button><div class="faq-a"><p>Go to the My Reports section and click Submit a Report. Complete the report form by providing the incident type, location, description, and supporting photo if available. Your report will first be sent to your assigned Barangay for validation.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">How can I request a veterinary appointment?<span class="arrow">▾</span></button><div class="faq-a"><p>Residents can request services such as Kapon (Spay/Neuter) or Pet Adoption through the Appointments page. After submitting your request, the DCVS Staff will review and schedule your appointment. You will receive updates once your appointment has been approved.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">How can I check the status of my reports and appointments?<span class="arrow">▾</span></button><div class="faq-a"><p>You can monitor the progress of all submitted reports and appointment requests directly from your dashboard. Statuses such as Pending, Validated, Scheduled, Completed, Rejected, or Cancelled are updated automatically as your request moves through the workflow.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">Can I edit my submitted information?<span class="arrow">▾</span></button><div class="faq-a"><p>Yes. Certain information such as pet details and personal profile information can be updated before they are finalized. However, submitted reports and appointment requests may have editing restrictions once they are already under review by the Barangay or DCVS Staff.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">Do I need to register before using the system?<span class="arrow">▾</span></button><div class="faq-a"><p>Yes. Residents must create an account before accessing features such as pet registration, report submission, appointment requests, and adoption listings. Registered accounts also allow users to track the status of their requests.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">Can I upload photos when submitting a report?<span class="arrow">▾</span></button><div class="faq-a"><p>Yes. The report form allows you to upload supporting images of the incident or animal involved. These photos help Barangay Officials and DCVS Staff validate and assess the reported case more accurately.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">Will I receive updates after submitting a request?<span class="arrow">▾</span></button><div class="faq-a"><p>Yes. The system automatically updates the status of your reports and appointment requests as they move through the validation and confirmation process. You can view these updates anytime from your dashboard.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">What services can I request through the system?<span class="arrow">▾</span></button><div class="faq-a"><p>Residents can request veterinary services such as Kapon (Spay/Neuter) appointments, browse available pets for adoption, register their pets, and submit reports involving stray animals, injured animals, suspected rabies cases, and other veterinary-related concerns.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">What should I do if I entered incorrect information?<span class="arrow">▾</span></button><div class="faq-a"><p>If your submission is still pending review, you may update eligible information from your dashboard where editing is available. If your request has already entered the validation process, you may need to contact your Barangay Official or the DCVS Office for assistance.</p></div></div>
  </div>
</section>

<section class="faq-section purple" style="max-width:1000px;margin-left:auto;margin-right:auto;">
  <h2>Account & System</h2>
  <div class="faq-list">
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">Who can create an account in the system?<span class="arrow">▾</span></button><div class="faq-a"><p>The system supports four account types: Resident, Barangay Official, DCVS Staff, and Administrator. Each role has different permissions and dashboard features based on their responsibilities.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">What happens after I submit a report?<span class="arrow">▾</span></button><div class="faq-a"><p>Your report is first received by your assigned Barangay Official for validation. Once validated, it is forwarded to the DCVS Staff for confirmation and further action. You will receive status updates throughout the process.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">Can I have multiple registered pets?<span class="arrow">▾</span></button><div class="faq-a"><p>Yes. Each resident account can register multiple pets. All registered pets are organized in your My Pets section, where you can view their profiles and information.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">How are appointments approved?<span class="arrow">▾</span></button><div class="faq-a"><p>Appointment requests are first submitted by residents and appear in the DCVS Staff's pending appointment list. Staff members review the request, assign an official schedule, and update its status. The scheduled appointment is then reflected in both the staff calendar and the resident dashboard.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">What happens if my report is rejected?<span class="arrow">▾</span></button><div class="faq-a"><p>If your report is rejected, you will be notified through your dashboard. The rejection reason provided by the reviewing personnel can be viewed by selecting the Why? or View Details option attached to the report.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">Is my personal information secure?<span class="arrow">▾</span></button><div class="faq-a"><p>Yes. The system uses role-based access control to ensure that only authorized users can access specific information. Residents can only access their own records, while Barangay Officials, DCVS Staff, and Administrators have permissions based on their assigned responsibilities.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">Can administrators change user roles?<span class="arrow">▾</span></button><div class="faq-a"><p>Yes. The Administrator has exclusive authority to create accounts, update user information, and assign or change system roles such as Resident, Barangay Official, DCVS Staff, and Administrator while preserving existing records and system history.</p></div></div>
  </div>
</section>

<section class="faq-section purple" style="max-width:1000px;margin-left:auto;margin-right:auto;">
  <h2>Terms &amp; Conditions</h2>
  <div class="faq-list">
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">What is covered in the Terms &amp; Conditions?<span class="arrow">▾</span></button><div class="faq-a"><p>The Terms &amp; Conditions govern your use of the Dasmari&ntilde;as City Veterinary Services (DCVS) Animal Welfare System. By creating an account or using the system, you agree to comply with these terms, including responsible use, account obligations, data privacy, appointment and report policies, pet adoption rules, and prohibited activities.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">What are my responsibilities as an account holder?<span class="arrow">▾</span></button><div class="faq-a"><p>You are responsible for maintaining the confidentiality of your login credentials, for all activities that occur under your account, and for ensuring that all information you submit is accurate and complete. You must notify DCVS immediately of any unauthorized use of your account.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">How should I use the system responsibly?<span class="arrow">▾</span></button><div class="faq-a"><p>You agree to use the system only for lawful purposes related to animal welfare services. You may not misuse the system by submitting false reports, tampering with data, accessing unauthorized areas, or interfering with the system's operation or security.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">What happens if I submit inaccurate information?<span class="arrow">▾</span></button><div class="faq-a"><p>You are required to provide accurate and truthful information when registering pets, submitting reports, booking appointments, or using any system feature. Knowingly submitting false information may result in rejection of your request, suspension of your account, or legal action if warranted.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">What are the policies on appointments and reports?<span class="arrow">▾</span></button><div class="faq-a"><p>Appointment requests are subject to staff review and scheduling availability. Reports undergo a validation process through your Barangay Official and DCVS Staff. Misuse of the appointment or reporting system, including no-shows or frivolous reports, may affect your account standing.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">What are my responsibilities when adopting a pet?<span class="arrow">▾</span></button><div class="faq-a"><p>Adopters must provide a safe and responsible home for the adopted pet, comply with local animal welfare ordinances, and maintain updated vaccination and veterinary care records. Failure to meet adoption responsibilities may result in revocation of adoption privileges.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">How is my personal data handled and protected?<span class="arrow">▾</span></button><div class="faq-a"><p>The system collects and processes your personal information solely for animal welfare and veterinary service purposes. Your data is stored securely and accessed only by authorized personnel based on their role. We do not share your personal data with third parties without your consent, except as required by law.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">What activities are prohibited on the system?<span class="arrow">▾</span></button><div class="faq-a"><p>Prohibited activities include: unauthorized access to other accounts, tampering with system data, submitting false or misleading information, using the system for commercial solicitation, attempting to breach system security, and any activity that disrupts the system's operation for other users.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">Can my account be suspended or terminated?<span class="arrow">▾</span></button><div class="faq-a"><p>Yes. DCVS reserves the right to suspend or terminate accounts that violate these terms, including but not limited to: providing false information, misuse of system features, unauthorized access attempts, or any activity that compromises the integrity or security of the system. You will be notified of the suspension or termination and the reason for it.</p></div></div>
    <div class="faq-item"><button class="faq-q" onclick="toggleFaq(this)">How will I know if the Terms &amp; Conditions are updated?<span class="arrow">▾</span></button><div class="faq-a"><p>DCVS may update these terms from time to time to reflect changes in system features, legal requirements, or operational policies. Users will be notified of material changes through system announcements or email. Continued use of the system after changes take effect constitutes acceptance of the updated terms.</p></div></div>
  </div>
</section>

<section class="contact-cta" style="max-width:1000px;margin-left:auto;margin-right:auto;">
  <p><strong>Still have a question?</strong> <span class="thin">If you have questions or need assistance, please do not hesitate and contact us by email</span></p>
  <a class="email-link" href="mailto:vetdasma@yahoo.com">vetdasma@yahoo.com →</a>
</section>

</div>
</div>

<script>
function toggleFaq(btn){
  const item = btn.closest('.faq-item');
  item.classList.toggle('open');
}
</script>

<?php include 'includes/footer.php'; ?>
</body>
</html>
