<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../email_config.php';
require_once __DIR__ . '/../../mailer.php';

define('CONTACT_TO_EMAIL', 'dcvsdasmarinas@gmail.com');

$mailStatus = '';      // 'success' | 'error'
$mailMessage = '';
$old = [
    'first_name' => '', 'last_name' => '', 'email' => '',
    'contact' => '', 'subject' => '', 'other_subject' => '', 'message' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    $old = [
        'first_name'    => trim($_POST['first_name'] ?? ''),
        'last_name'     => trim($_POST['last_name'] ?? ''),
        'email'         => trim($_POST['email'] ?? ''),
        'contact'       => trim($_POST['contact'] ?? ''),
        'subject'       => $_POST['subject'] ?? '',
        'other_subject' => trim($_POST['other_subject'] ?? ''),
        'message'       => trim($_POST['message'] ?? ''),
    ];

    $errors = [];
    if ($old['first_name'] === '') $errors[] = 'First name is required.';
    if ($old['last_name'] === '') $errors[] = 'Last name is required.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if ($old['contact'] === '') $errors[] = 'Contact number is required.';
    if ($old['subject'] === '') $errors[] = 'Please select a subject.';
    if ($old['subject'] === 'other' && $old['other_subject'] === '') $errors[] = 'Please specify your subject.';
    if ($old['message'] === '') $errors[] = 'Message is required.';
    if (!isset($_POST['news'])) $errors[] = 'Please agree to the Data Privacy Act of 2012 to proceed.';

    if (!empty($errors)) {
        $mailStatus = 'error';
        $mailMessage = implode(' ', $errors);
    } else {
        $subjectMap = [
            'general'      => 'General Inquiry',
            'technical'    => 'Technical Support',
            'registration' => 'Pet Registration',
            'appointment'  => 'Appointment Assistance',
            'report'       => 'Report Follow-up',
            'adoption'     => 'Adoption Inquiry',
            'other'        => 'Other',
        ];
        $subjectLabel = $subjectMap[$old['subject']] ?? 'General Inquiry';
        if ($old['subject'] === 'other' && $old['other_subject'] !== '') {
            $subjectLabel .= ' — ' . $old['other_subject'];
        }

        $fullName = $old['first_name'] . ' ' . $old['last_name'];

        $rows = [
            ['From', $fullName],
            ['Email', $old['email']],
            ['Contact Number', $old['contact']],
            ['Subject', $subjectLabel],
            ['Submitted', date('F j, Y \a\t g:i A')],
        ];

        $content = '<p style="margin:0 0 16px;">A resident sent a message through the DCVS contact form.</p>';
        $content .= dcvsInfoTable($rows);
        $content .= dcvsStaffMessage($old['message'], 'Message');

        $ctaLabel = 'Reply to ' . $fullName;
        $ctaUrl = 'mailto:' . $old['email'] . '?subject=' . rawurlencode('Re: DCVS inquiry — ' . $subjectLabel);

        $html = dcvsEmailTemplate('New contact message', $content, [
            'badge' => $subjectLabel,
            'cta_label' => $ctaLabel,
            'cta_url' => $ctaUrl,
        ]);

        $result = sendSmtpMail(CONTACT_TO_EMAIL, 'Contact Form: ' . $subjectLabel, $html);
        $success = is_array($result) ? !empty($result['success']) : !empty($result);

        if ($success) {
            $mailStatus = 'success';
            $mailMessage = 'Thank you! Your message has been sent successfully. Our team will get back to you as soon as possible.';
            $old = ['first_name' => '', 'last_name' => '', 'email' => '', 'contact' => '', 'subject' => '', 'other_subject' => '', 'message' => ''];
        } else {
            $mailStatus = 'error';
            $mailMessage = 'Your message could not be sent at the moment. Please try again later. (Details: ' . htmlspecialchars(is_array($result) ? ($result['error'] ?? 'Unknown') : 'Unknown') . ')';
        }
    }
}

$pageTitle = 'Contact Us';
$activePage = 'contact';
include __DIR__ . '/../../includes/header.php';
?>

<style>
.contact-page{background:#f2f2f0;min-height:100vh;}
.contact-page .wrap{max-width:1180px;margin:0 auto;padding:0 32px;}
.contact-pad{padding:140px 32px 120px;}
.grid{
  display:grid;grid-template-columns:1fr 1fr;gap:64px;align-items:start;
}
@media(max-width:900px){.grid{grid-template-columns:1fr;}}

.contact-page *{font-family:'Inter',system-ui,sans-serif !important;}

.eyebrow{font-size:12.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#8a93ab;margin-bottom:14px;}
.left h1{
  font-weight:800;font-size:48px;line-height:1.1;letter-spacing:-0.025em;
  margin:0 0 32px;max-width:750px;color:var(--ink);
}
.left p{font-size:16px;color:#4b4b4b;margin:0 0 20px;max-width:480px;}
.left a{color:var(--ink);text-decoration:underline;}

.card.ps-card{
  background:#fff;border:1px solid #e6e8eb;border-radius:14px;padding:28px 32px;
  box-shadow:0 1px 2px rgba(0,0,0,0.02);
}
.ps-form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:14px;}
.ps-field{margin-bottom:14px;}
.ps-field label{display:block;font-size:13px;color:#4b5563;margin-bottom:6px;}
.ps-field label .req{color:#4b5563;margin-left:2px;}
.ps-field input,.ps-field select,.ps-field textarea{
  width:100%;padding:10px 12px;font-size:14px;border-radius:8px;
  border:1px solid #e2e5ea;background:#f9fafb;color:#1f2328;
  outline:none;transition:background .15s,border-color .15s,box-shadow .15s;
  box-sizing:border-box;
}
.ps-field input:focus,.ps-field select:focus,.ps-field textarea:focus{
  background:#fff;border-color:#000;box-shadow:0 0 0 3px rgba(0,0,0,0.08);
}
.ps-field select{cursor:pointer;}
.ps-field textarea{min-height:80px;resize:vertical;}

.privacy-link{color:#000;text-decoration:underline;cursor:pointer;}
.privacy-link:hover{text-decoration:none;}

.modal-overlay{
  display:none;position:fixed;top:0;left:0;width:100%;height:100%;
  background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;
}
.modal-overlay.active{display:flex;}
.modal{
  background:#fff;border-radius:16px;padding:40px;max-width:600px;width:90%;
  max-height:80vh;overflow-y:auto;position:relative;
}
.modal h2{font-weight:800;font-size:28px;margin:0 0 20px;letter-spacing:-0.025em;}
.modal p{font-size:15px;line-height:1.6;color:#4b4b4b;margin:0 0 16px;}
.modal-close{
  position:absolute;top:20px;right:20px;background:none;border:none;
  font-size:28px;cursor:pointer;color:#6b6b6b;width:32px;height:32px;
  display:flex;align-items:center;justify-content:center;border-radius:50%;
}
.modal-close:hover{background:#f2f2f0;color:#111;}

.error-message{font-style:italic;color:#dc2626;font-size:14px;margin:0 0 20px;}
.checkbox-row{display:flex;align-items:flex-start;gap:12px;margin-bottom:18px;}
.checkbox-row input[type="checkbox"]{width:18px;height:18px;margin-top:2px;accent-color:#000;flex-shrink:0;}
.checkbox-row label{font-size:14px;color:#4b5563;line-height:1.5;}

.ps-btn{padding:9px 18px;font-size:13px;font-weight:600;border-radius:8px;cursor:pointer;border:1px solid transparent;}
.ps-btn-primary{background:#000;color:#fff;border-color:#000;}
.ps-btn-primary:hover{background:#1a1a1a;}
.ps-btn-primary:disabled{background:#c7c9d9;border-color:#c7c9d9;cursor:not-allowed;}
.privacy-note{margin:16px 0 0;font-size:13px;color:#6b7280;line-height:1.5;}

@media(max-width:900px){
  .contact-pad{padding:100px 24px 80px;}
  .left h1{font-size:36px;}
}
@media(max-width:600px){
  .contact-pad{padding:60px 16px 40px;}
  .visit-section{padding:40px 16px 50px !important;}
  .map-iframe{height:280px !important;}
}
@media(max-width:520px){
  .ps-form-row{grid-template-columns:1fr;}
  .card.ps-card{padding:20px;}
  .left h1{font-size:28px;}
}
@media(max-width:430px){
  .visit-section{padding:30px 12px 40px !important;}
  .map-iframe{height:200px !important;}
  .contact-pad{padding:50px 12px 30px;}
}
</style>

<div class="contact-page">
  <section class="contact-pad">
    <div class="wrap grid">
      <div class="left">
        <div class="eyebrow">Contact</div>
        <h1>Get in touch<br>with DCVS</h1>
        <p>Have questions about pet registration, veterinary appointments, animal adoption, or stray and rabies case reporting? Our team is here to assist you. Complete the contact form and we'll respond to your inquiry as soon as possible.</p>
        <p>Whether you need help using the system, following up on a submitted report, or requesting information about our veterinary services, we're ready to help. Please provide accurate contact details so we can respond efficiently.</p>
      </div>
      <div class="card ps-card">
        <?php if ($mailStatus === 'success'): ?>
          <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d;border-radius:8px;padding:14px 16px;font-size:14px;margin-bottom:16px;"><?php echo $mailMessage; ?></div>
        <?php elseif ($mailStatus === 'error'): ?>
          <div style="background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d;border-radius:8px;padding:14px 16px;font-size:14px;margin-bottom:16px;"><?php echo $mailMessage; ?></div>
        <?php endif; ?>
        <form method="post" action="contact.php" onsubmit="return validateForm(event);">
          <div class="ps-form-row">
            <div class="ps-field">
              <label>First Name<span class="req">*</span></label>
              <input type="text" name="first_name" required value="<?php echo htmlspecialchars($old['first_name']); ?>">
            </div>
            <div class="ps-field">
              <label>Last Name<span class="req">*</span></label>
              <input type="text" name="last_name" required value="<?php echo htmlspecialchars($old['last_name']); ?>">
            </div>
          </div>
          <div class="ps-field">
            <label>Email Address<span class="req">*</span></label>
            <input type="email" name="email" required value="<?php echo htmlspecialchars($old['email']); ?>">
          </div>
          <div class="ps-field">
            <label>Contact Number<span class="req">*</span></label>
            <input type="text" name="contact" required value="<?php echo htmlspecialchars($old['contact']); ?>">
          </div>
          <div class="ps-field">
            <label>Subject<span class="req">*</span></label>
            <select id="subjectSelect" name="subject" required onchange="toggleOtherField()">
              <option value="">Select a subject</option>
              <option value="general" <?php echo $old['subject'] === 'general' ? 'selected' : ''; ?>>General Inquiry</option>
              <option value="technical" <?php echo $old['subject'] === 'technical' ? 'selected' : ''; ?>>Technical Support</option>
              <option value="registration" <?php echo $old['subject'] === 'registration' ? 'selected' : ''; ?>>Pet Registration</option>
              <option value="appointment" <?php echo $old['subject'] === 'appointment' ? 'selected' : ''; ?>>Appointment Assistance</option>
              <option value="report" <?php echo $old['subject'] === 'report' ? 'selected' : ''; ?>>Report Follow-up</option>
              <option value="adoption" <?php echo $old['subject'] === 'adoption' ? 'selected' : ''; ?>>Adoption Inquiry</option>
              <option value="other" <?php echo $old['subject'] === 'other' ? 'selected' : ''; ?>>Other</option>
            </select>
          </div>
          <div class="ps-field" id="otherField" style="display:none;">
            <label>Please specify<span class="req">*</span></label>
            <input type="text" id="otherSubject" name="other_subject" value="<?php echo htmlspecialchars($old['other_subject']); ?>">
          </div>
          <div class="ps-field">
            <label>Message<span class="req">*</span></label>
            <textarea rows="5" name="message" required><?php echo htmlspecialchars($old['message']); ?></textarea>
          </div>
          <div class="checkbox-row">
            <input type="checkbox" id="news" name="news" <?php echo isset($_POST['news']) ? 'checked' : ''; ?>>
            <label for="news">I agree to the collection and processing of my information for responding to my inquiry in accordance with the <span class="privacy-link" onclick="openModal()">Data Privacy Act of 2012</span>.</label>
          </div>
          <p class="error-message" id="privacyError" style="display:none;">Please agree to the Data Privacy Act of 2012 to proceed.</p>
          <button type="submit" name="contact_submit" value="1" class="ps-btn ps-btn-primary" style="width:100%;">Send</button>
          <p class="privacy-note">Your information will only be used to respond to your inquiry and will be handled in accordance with the Data Privacy Act of 2012. DCVS values your privacy and protects your personal information.</p>
        </form>
      </div>
    </div>
  </section>

  <!-- ── VISIT OUR OFFICE ── -->
  <section class="visit-section" style="padding:80px 32px 100px;">
    <div class="wrap grid">
      <div class="left">
        <div class="eyebrow">Visit Our Office</div>
        <h1 style="margin:0 0 20px;">Come see us at<br>DCVS Main Office</h1>
        <p>Residents are welcome to visit the Dasmariñas City Veterinary Services Main Office for inquiries, appointments, pet registrations, animal adoption, lost-and-found concerns, and other veterinary services. Our team is ready to assist you in person during office hours.</p>

        <div style="background:#f7f7f5;border-radius:12px;padding:24px;margin:28px 0;">
          <div style="font-size:15px;font-weight:700;color:#0f172a;margin-bottom:4px;">DCVS Main Office</div>
          <div style="font-size:14px;color:#4b4b4b;line-height:1.6;margin-bottom:16px;">
            Pala-pala Rd, Dasmariñas, 4114 Cavite
          </div>
          <div style="font-size:13px;color:#6b6b6b;line-height:1.7;">
            <div><strong style="color:#0f172a;">Office Hours:</strong> Monday – Friday, 8:00 AM – 5:00 PM</div>
            <div style="margin-top:4px;"><strong style="color:#0f172a;">Phone:</strong> (046) 432-1234</div>
            <div style="margin-top:4px;"><strong style="color:#0f172a;">Email:</strong> dcvs_dasma@yahoo.com</div>
          </div>
        </div>

        <div style="display:flex;gap:12px;flex-wrap:wrap;">
          <a href="https://maps.app.goo.gl/vgtNgyigFkoQ1YCB7" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;padding:12px 22px;background:#000;color:#fff;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;transition:background 0.15s;" onmouseover="this.style.background='#1a1a1a'" onmouseout="this.style.background='#000'"><svg viewBox="0 0 24 24" width="16" height="16" fill="#fff"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>Open in Google Maps</a>
          <a href="https://www.waze.com/ul?ll=14.2513411%2C120.9476574&navigate=yes&q=Dasmari%C3%B1as+City+Veterinary+Office" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;padding:12px 22px;background:#000;color:#fff;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;transition:background 0.15s;" onmouseover="this.style.background='#1a1a1a'" onmouseout="this.style.background='#000'"><svg viewBox="0 0 24 24" width="16" height="16" fill="#fff"><path d="M20.56 3.91c.59.59.59 1.54 0 2.13l-7.5 7.5-5.5-5.5 7.5-7.5c.59-.59 1.54-.59 2.13 0l3.37 3.37zM3.5 20.5l4.5-4.5-2-2-4.5 4.5 2 2zm7-2l-2-2 5.5-5.5 2 2-5.5 5.5z"/></svg>Get Directions (Waze)</a>
        </div>
      </div>
      <div>
        <div style="background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,0.03),0 20px 40px rgba(0,0,0,0.06);">
          <div style="position:relative;">
            <iframe class="map-iframe" src="https://www.google.com/maps?q=Dasmari%C3%B1as+City+Veterinary+Office&output=embed" width="100%" height="420" style="border:0;display:block;" allowfullscreen="" loading="lazy"></iframe>
            <a href="https://maps.app.goo.gl/vgtNgyigFkoQ1YCB7" target="_blank" rel="noopener" style="position:absolute;bottom:16px;right:16px;background:#fff;border-radius:10px;padding:10px 16px;font-size:13px;font-weight:600;color:#1f2328;text-decoration:none;box-shadow:0 2px 12px rgba(0,0,0,0.15);display:inline-flex;align-items:center;gap:8px;z-index:10;transition:box-shadow 0.15s;" onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,0.2)'" onmouseout="this.style.boxShadow='0 2px 12px rgba(0,0,0,0.15)'"><svg viewBox="0 0 24 24" width="16" height="16" fill="#4285F4"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>Open in Google Maps</a>
          </div>
        </div>
      </div>
    </div>
  </section>

</div>

<!-- Modal -->
<div class="modal-overlay" id="privacyModal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
    <h2>Data Privacy Act of 2012</h2>
    <p>By submitting this form, you consent to the collection and processing of your personal information in accordance with the Data Privacy Act of 2012 (Republic Act No. 10173). The information you provide will be used solely by the Dasmariñas City Veterinary Services (DCVS) to respond to your inquiry, provide requested assistance, and improve our services. Your personal data will be kept confidential, securely stored, and will not be shared with unauthorized parties unless required by law.</p>
  </div>
</div>

<script>
function toggleOtherField() {
  const select = document.getElementById('subjectSelect');
  const otherField = document.getElementById('otherField');
  const otherInput = document.getElementById('otherSubject');
  if (select.value === 'other') {
    otherField.style.display = 'block';
    otherInput.required = true;
  } else {
    otherField.style.display = 'none';
    otherInput.required = false;
    otherInput.value = '';
  }
}
function validateForm(event) {
  const checkbox = document.getElementById('news');
  const errorElement = document.getElementById('privacyError');
  if (!checkbox.checked) {
    errorElement.style.display = 'block';
    return false;
  }
  errorElement.style.display = 'none';
  return true;
}
function openModal() { document.getElementById('privacyModal').classList.add('active'); }
function closeModal() { document.getElementById('privacyModal').classList.remove('active'); }
document.getElementById('privacyModal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});
toggleOtherField();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
</body>
</html>
