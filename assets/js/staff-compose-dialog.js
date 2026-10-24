var composeSubjectTemplates = {
  general: ['Important Update from DCVS', 'Notification from DCVS Staff'],
  report: ['Update on Animal Report', 'Action Needed on Report', 'Follow-up on Report'],
  appointment: ['Appointment Confirmation', 'Appointment Reminder', 'Appointment Rescheduled'],
  adoption: ['Update on Adoption Request', 'Adoption Application Status'],
  followup: ['Friendly Reminder from DCVS', 'Follow-up on Previous Concern'],
  other: []
};
var composeMessageTypes = {
  resident: [
    { value: 'general', label: 'General Message' },
    { value: 'report', label: 'Animal Report' },
    { value: 'appointment', label: 'Appointment' },
    { value: 'adoption', label: 'Adoption Request' },
    { value: 'followup', label: 'Follow-up / Reminder' },
    { value: 'other', label: 'Other' }
  ],
  barangay: [
    { value: 'barangay_general', label: 'General Message' },
    { value: 'barangay_report', label: 'Animal Report' },
    { value: 'barangay_rescue', label: 'Rescue Case' },
    { value: 'barangay_pets', label: 'Pet Records' },
    { value: 'barangay_vaccination', label: 'Vaccination' },
    { value: 'barangay_coordination', label: 'Barangay Coordination' },
    { value: 'barangay_followup', label: 'Follow-up / Reminder' },
    { value: 'barangay_other', label: 'Other' }
  ]
};
var composeBarangaySubjectTemplates = {
  barangay_general: ['Barangay Coordination'],
  barangay_report: ['Report Follow-up', 'Report Validation'],
  barangay_rescue: ['Rescue Coordination'],
  barangay_pets: ['Pet Record Verification'],
  barangay_vaccination: ['Vaccination Update'],
  barangay_coordination: ['Barangay Coordination'],
  barangay_followup: ['Follow-up Required', 'Reminder'],
  barangay_other: []
};
var composeReturnFocus = null;

function updateComposeMessageTypeOptions() {
  var role = document.getElementById('recipient_role').value;
  var select = document.getElementById('message_type');
  var options = composeMessageTypes[role] || composeMessageTypes.resident;
  select.innerHTML = '';
  options.forEach(function(option) {
    var element = document.createElement('option');
    element.value = option.value;
    element.textContent = option.label;
    select.appendChild(element);
  });
  select.value = options[0].value;
  document.getElementById('subject').value = '';
  onMessageTypeChange();
}

function updateRecipients() {
  var role = document.getElementById('recipient_role').value;
  var select = document.getElementById('recipient_id');
  var barangaySelect = document.getElementById('recipientBarangay');
  var hasRole = role === 'resident' || role === 'barangay';
  document.getElementById('recipientBarangayGroup').classList.toggle('visible', hasRole);
  document.getElementById('recipientAccountGroup').classList.remove('visible');
  document.getElementById('recipientAccountLabel').innerHTML = (role === 'barangay' ? 'Registered Accounts' : 'Registered Clients') + ' <span class="req">*</span>';
  barangaySelect.value = '';
  barangaySelect.disabled = !hasRole;
  select.disabled = true;
  select.required = false;
  select.innerHTML = '<option value="">' + (hasRole ? 'Select a barangay first...' : 'Select a role first...') + '</option>';
  document.getElementById('recipientCount').textContent = '';
}

function onRoleChange() {
  updateRecipients();
  updateComposeMessageTypeOptions();
}

function filterCommunicationRecipients() {
  var role = document.getElementById('recipient_role').value;
  var barangayId = document.getElementById('recipientBarangay').value;
  var select = document.getElementById('recipient_id');
  var accountGroup = document.getElementById('recipientAccountGroup');
  var count = document.getElementById('recipientCount');
  var users = role === 'barangay' ? barangayUsers : residents;

  select.innerHTML = '<option value="">' + (barangayId ? 'Select a registered account...' : 'Select a barangay first...') + '</option>';
  accountGroup.classList.toggle('visible', !!barangayId);
  select.disabled = !barangayId;
  select.required = !!barangayId;
  count.textContent = '';
  if (!barangayId) return;

  var matches = users.filter(function(user) {
    return String(user.barangay_id || '') === String(barangayId);
  });
  count.textContent = matches.length + ' registered ' + (role === 'barangay' ? 'account' : 'client') + (matches.length !== 1 ? 's' : '') + ' found';
  matches.forEach(function(user) {
    var option = document.createElement('option');
    option.value = user.user_id;
    option.textContent = user.full_name + (user.email ? ' — ' + user.email : '');
    select.appendChild(option);
  });
  if (!matches.length) {
    select.innerHTML = '<option value="">No registered ' + (role === 'barangay' ? 'accounts' : 'clients') + ' for this barangay</option>';
    select.disabled = true;
    select.required = false;
  }
}

function onMessageTypeChange() {
  var type = document.getElementById('message_type').value;
  var suggestions = document.getElementById('subjectSuggestions');
  var subjectInput = document.getElementById('subject');
  suggestions.innerHTML = '';
  var templates = composeBarangaySubjectTemplates[type] || composeSubjectTemplates[type] || [];
  templates.forEach(function(subject) {
    var button = document.createElement('button');
    button.type = 'button';
    button.textContent = subject;
    button.onclick = function() { subjectInput.value = subject; };
    suggestions.appendChild(button);
  });
}

function openComposeDialog() {
  composeReturnFocus = document.activeElement;
  document.getElementById('composeDialog').classList.add('active');
  document.body.style.overflow = 'hidden';
  document.getElementById('recipient_role').focus();
}

function closeComposeDialog() {
  document.getElementById('composeDialog').classList.remove('active');
  document.body.style.overflow = document.querySelector('.modal.active') ? 'hidden' : '';
  if (composeReturnFocus && document.contains(composeReturnFocus)) composeReturnFocus.focus();
  composeReturnFocus = null;
}

function prepareComposeForResident(client) {
  if (!client || !client.user_id) return false;
  var roleSelect = document.getElementById('recipient_role');
  var barangaySelect = document.getElementById('recipientBarangay');
  roleSelect.value = 'resident';
  onRoleChange();

  var barangayId = String(client.barangay_id || '');
  var barangayOption = Array.prototype.find.call(barangaySelect.options, function(option) {
    return option.value === barangayId;
  });
  if (!barangayOption && barangayId && client.barangay_name) {
    barangayOption = document.createElement('option');
    barangayOption.value = barangayId;
    barangayOption.textContent = client.barangay_name;
    barangaySelect.appendChild(barangayOption);
  }
  if (!barangayOption) return false;

  barangaySelect.value = barangayId;
  filterCommunicationRecipients();

  var recipientSelect = document.getElementById('recipient_id');
  if (!Array.prototype.some.call(recipientSelect.options, function(option) {
    return option.value === String(client.user_id);
  })) {
    var recipientOption = document.createElement('option');
    recipientOption.value = client.user_id;
    recipientOption.textContent = client.full_name + (client.email ? ' — ' + client.email : '');
    recipientSelect.appendChild(recipientOption);
  }
  recipientSelect.value = String(client.user_id);
  document.getElementById('subject').value = '';
  document.getElementById('message').value = '';
  document.getElementById('sendBtn').disabled = false;
  document.getElementById('sendBtnLabel').textContent = 'Send Message';
  return true;
}

function openClientComposeFromProfile() {
  var client = currentProfile && currentProfile.client;
  if (!prepareComposeForResident(client)) return;
  openComposeDialog();
}

function prefillComposeFromQuery() {
  var params = new URLSearchParams(window.location.search);
  var recipientId = params.get('recipient_id');
  if (!recipientId || params.get('recipient_role') !== 'resident') return;
  var resident = residents.find(function(user) {
    return String(user.user_id) === recipientId;
  });
  if (prepareComposeForResident(resident)) openComposeDialog();
}

document.addEventListener('keydown', function(event) {
  var composeDialog = document.getElementById('composeDialog');
  if (event.key === 'Escape' && composeDialog && composeDialog.classList.contains('active')) closeComposeDialog();
});

document.getElementById('composeForm').addEventListener('submit', function(event) {
  var role = document.getElementById('recipient_role').value;
  var barangay = document.getElementById('recipientBarangay').value;
  var recipient = document.getElementById('recipient_id').value;
  if ((role === 'resident' || role === 'barangay') && (!barangay || !recipient)) {
    event.preventDefault();
    document.getElementById('recipientCount').textContent = !barangay
      ? 'Select a barangay before sending.'
      : 'Select a registered ' + (role === 'barangay' ? 'barangay account' : 'client') + ' before sending.';
    return;
  }
  document.getElementById('sendBtn').disabled = true;
  document.getElementById('sendBtnLabel').textContent = 'Sending...';
});

prefillComposeFromQuery();
