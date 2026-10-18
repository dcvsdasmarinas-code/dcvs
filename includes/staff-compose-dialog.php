<div class="modal" id="composeDialog" onclick="if(event.target === this) closeComposeDialog()">
  <section class="comm-compose-dialog-content" role="dialog" aria-modal="true" aria-labelledby="composeDialogTitle" aria-describedby="composeDialogDescription">
    <button type="button" class="comm-compose-close" onclick="closeComposeDialog()" aria-label="Close compose message dialog"><i class="fas fa-times"></i></button>
    <div class="comm-compose-header">
      <h2 id="composeDialogTitle">Compose Message</h2>
      <p id="composeDialogDescription">Send a message directly to a resident or barangay officer.</p>
    </div>
    <form method="POST" action="<?php echo htmlspecialchars($appBaseUrl . '/staff-communication.php', ENT_QUOTES, 'UTF-8'); ?>" id="composeForm" class="comm-compose-form">
      <div class="comm-compose-body">
        <div class="form-group">
          <label for="recipient_role">Send To <span class="req">*</span></label>
          <select id="recipient_role" name="recipient_role" onchange="onRoleChange()" required>
            <option value="">Select role...</option>
            <option value="resident">Resident</option>
            <option value="barangay">Barangay Officer</option>
          </select>
        </div>
        <div class="form-group recipient-filter-group" id="recipientBarangayGroup">
          <label for="recipientBarangay">Barangay <span class="req">*</span></label>
          <select id="recipientBarangay" onchange="filterCommunicationRecipients()" disabled>
            <option value="">Select Barangay...</option>
            <?php foreach ($composeBarangays as $barangay): ?>
              <option value="<?php echo (int)$barangay['barangay_id']; ?>"><?php echo htmlspecialchars($barangay['barangay_name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group recipient-filter-group" id="recipientAccountGroup">
          <label for="recipient_id" id="recipientAccountLabel">Registered Accounts <span class="req">*</span></label>
          <select id="recipient_id" name="recipient_id" required disabled>
            <option value="">Select a barangay first...</option>
          </select>
          <div class="recipient-count" id="recipientCount"></div>
        </div>
        <div class="form-group">
          <label for="message_type">Message Type <span class="req">*</span></label>
          <select id="message_type" name="message_type" onchange="onMessageTypeChange()" required>
            <option value="general">General Message</option>
            <option value="report">Animal Report</option>
            <option value="appointment">Appointment</option>
            <option value="adoption">Adoption Request</option>
            <option value="followup">Follow-up / Reminder</option>
            <option value="other">Other</option>
            <option value="barangay_general">General Message</option>
            <option value="barangay_report">Animal Report</option>
            <option value="barangay_rescue">Rescue Case</option>
            <option value="barangay_pets">Pet Records</option>
            <option value="barangay_vaccination">Vaccination</option>
            <option value="barangay_coordination">Barangay Coordination</option>
            <option value="barangay_followup">Follow-up / Reminder</option>
            <option value="barangay_other">Other</option>
          </select>
        </div>
        <div class="form-group">
          <label for="subject">Subject <span class="req">*</span></label>
          <input type="text" id="subject" name="subject" placeholder="e.g. Update on your report" required />
          <div class="subject-suggestions" id="subjectSuggestions"></div>
        </div>
        <div class="form-group">
          <label for="message">Message <span class="req">*</span></label>
          <textarea id="message" name="message" placeholder="Type your message here..." required></textarea>
        </div>
      </div>
      <input type="hidden" name="send_message" value="1">
      <div class="comm-compose-footer">
        <button type="button" class="sd-btn sd-btn-outline" onclick="closeComposeDialog()">Cancel</button>
        <button type="submit" class="sd-btn sd-btn-default btn-send" id="sendBtn"><span id="sendBtnLabel">Send Message</span></button>
      </div>
    </form>
  </section>
</div>
